{{-- The sidebar entry: for the ones with the permission, and for everyone where the page is public (ai.overview_public) --}}
@if(config('ai.overview_public') || (Auth::user() && Auth::user()->hasRoleOrPermission('admin|view everything|develop with ai')))
    <x-rpd::nav-item icon="robot" label="AI overview" route="ai.develop" active="/ai/develop" />
@endif
