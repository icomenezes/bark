<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-gray-800 border border-gray-700 rounded-md font-semibold text-xs text-gray-300 uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-900 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
