@props(['name'=>'arrow-right'])
@php($iconName=\App\Support\UiIcon::name($name))
<svg {{ $attributes->class(['ui-icon','ui-icon--'.$iconName]) }} data-ui-icon="{{ $iconName }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ \App\Support\UiIcon::path($iconName) }}"/></svg>
