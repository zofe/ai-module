@if(Auth::user() && Auth::user()->hasRoleOrPermission('admin|view everything|view ai status'))
    <x-rpd::nav-item icon="robot" label="AI readiness" route="ai.status" active="/ai/status" />
@endif
