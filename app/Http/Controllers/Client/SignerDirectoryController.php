<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\SavedSigner;
use App\Models\SignerGroup;
use App\Services\SignerDirectoryService;
use Illuminate\Http\Request;

class SignerDirectoryController extends Controller
{
    public function __construct(private SignerDirectoryService $directory) {}

    public function index()
    {
        $signers = auth()->user()->savedSigners()->orderBy('name')->get();
        $groups = auth()->user()->signerGroups()->with('members')->orderBy('name')->get();

        return view('client.signers.index', compact('signers', 'groups'));
    }

    public function store(Request $request)
    {
        $data = $this->validatedSignerData($request);
        $this->directory->createSigner(auth()->user(), $data);

        return back()->with('success', 'Signatário salvo.');
    }

    public function edit(SavedSigner $savedSigner)
    {
        $this->authorizeOwner($savedSigner);

        return view('client.signers.edit', ['savedSigner' => $savedSigner]);
    }

    public function update(Request $request, SavedSigner $savedSigner)
    {
        $this->authorizeOwner($savedSigner);
        $data = $this->validatedSignerData($request);
        $this->directory->updateSigner($savedSigner, $data);

        return back()->with('success', 'Signatário atualizado.');
    }

    public function destroy(SavedSigner $savedSigner)
    {
        $this->authorizeOwner($savedSigner);
        $this->directory->deleteSigner($savedSigner);

        return back()->with('success', 'Signatário removido.');
    }

    public function search(Request $request)
    {
        $results = $this->directory->search(auth()->user(), (string) $request->query('q', ''));

        return response()->json($results->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'channel' => $s->channel,
            'email' => $s->email, 'whatsapp' => $s->whatsapp, 'auth_method' => $s->auth_method,
        ]));
    }

    public function storeGroup(Request $request)
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'members' => ['array'], 'members.*' => ['integer']]);

        $this->directory->createGroup(auth()->user(), $request->input('name'), $request->input('members', []));

        return back()->with('success', 'Grupo criado.');
    }

    public function editGroup(SignerGroup $signerGroup)
    {
        $this->authorizeOwnerGroup($signerGroup);
        $signers = auth()->user()->savedSigners()->orderBy('name')->get();

        return view('client.signers.edit-group', ['signerGroup' => $signerGroup, 'signers' => $signers]);
    }

    public function updateGroup(Request $request, SignerGroup $signerGroup)
    {
        $this->authorizeOwnerGroup($signerGroup);
        $request->validate(['name' => ['required', 'string', 'max:255'], 'members' => ['array'], 'members.*' => ['integer']]);

        $this->directory->updateGroup($signerGroup, $request->input('name'), $request->input('members', []));

        return back()->with('success', 'Grupo atualizado.');
    }

    public function destroyGroup(SignerGroup $signerGroup)
    {
        $this->authorizeOwnerGroup($signerGroup);
        $this->directory->deleteGroup($signerGroup);

        return back()->with('success', 'Grupo removido.');
    }

    private function validatedSignerData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:email,whatsapp'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'auth_method' => ['required', 'in:link,email_otp,whatsapp_otp'],
        ]);
    }

    private function authorizeOwner(SavedSigner $signer): void
    {
        abort_unless($signer->user_id === auth()->id(), 403);
    }

    private function authorizeOwnerGroup(SignerGroup $group): void
    {
        abort_unless($group->user_id === auth()->id(), 403);
    }
}
