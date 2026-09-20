@if(Auth::user() && Auth::user()->hasRoleOrPermission('admin|view everything|develop with ai'))
    <x-rpd::nav-item icon="robot" label="Develop with AI" route="ai.develop" active="/ai/develop" />
@endif
