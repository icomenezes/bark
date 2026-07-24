<?php

namespace App\Services;

use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SignerDirectoryService
{
    private const MAX_SIGNERS_PER_USER = 100;

    private const MAX_GROUPS_PER_USER = 20;

    public function createSigner(User $user, array $data): SavedSigner
    {
        $this->validateSignerData($data);

        if ($user->savedSigners()->count() >= self::MAX_SIGNERS_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.self::MAX_SIGNERS_PER_USER.' signatários salvos atingido.',
            ]);
        }

        return $user->savedSigners()->create($data);
    }

    public function updateSigner(SavedSigner $signer, array $data): SavedSigner
    {
        $this->validateSignerData($data);
        $signer->update($data);

        return $signer;
    }

    public function deleteSigner(SavedSigner $signer): void
    {
        $signer->delete();
    }

    /** @param  list<int>  $savedSignerIds */
    public function createGroup(User $user, string $name, array $savedSignerIds = []): SignerGroup
    {
        if ($user->signerGroups()->count() >= self::MAX_GROUPS_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'Limite de '.self::MAX_GROUPS_PER_USER.' grupos atingido.',
            ]);
        }

        $group = $user->signerGroups()->create(['name' => $name]);
        $group->members()->sync($this->ownedSignerIds($user, $savedSignerIds));

        return $group;
    }

    /** @param  list<int>  $savedSignerIds */
    public function updateGroup(SignerGroup $group, string $name, array $savedSignerIds): SignerGroup
    {
        $group->update(['name' => $name]);
        $group->members()->sync($this->ownedSignerIds($group->user, $savedSignerIds));

        return $group;
    }

    /**
     * Filtra os IDs para só os signatários salvos do próprio usuário — nunca
     * confiar em IDs de saved_signers vindos direto do request (IDOR: um
     * usuário poderia tentar associar contatos de outro cliente ao seu grupo).
     *
     * @param  list<int>  $savedSignerIds
     * @return list<int>
     */
    private function ownedSignerIds(User $user, array $savedSignerIds): array
    {
        return $user->savedSigners()->whereIn('id', $savedSignerIds)->pluck('id')->all();
    }

    public function deleteGroup(SignerGroup $group): void
    {
        $group->delete();
    }

    public function search(User $user, string $query): Collection
    {
        return $user->savedSigners()
            ->where('name', 'like', '%'.$query.'%')
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    private function validateSignerData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:email,whatsapp'],
            'email' => ['nullable', 'email', 'max:255', 'required_if:channel,email'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'required_if:channel,whatsapp'],
            'auth_method' => ['required', 'in:link,email_otp,whatsapp_otp'],
        ]);

        $validator->validate();

        $allowed = $data['channel'] === 'whatsapp' ? ['link', 'whatsapp_otp'] : ['link', 'email_otp'];
        if (! in_array($data['auth_method'], $allowed, true)) {
            throw ValidationException::withMessages([
                'auth_method' => 'Método de verificação incompatível com o canal escolhido.',
            ]);
        }
    }
}
