@props(['class' => 'w-5 h-5'])

<svg {{ $attributes->merge(['class' => $class]) }} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M4 19h16M4 15c2-3 3.5-5 5-5s1.5 2 3 2 3-4 5-7c1 3 1.5 6 1 10"/>
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M15.5 5.5l1.5-1.5a1.5 1.5 0 012 2l-1.5 1.5"/>
</svg>
