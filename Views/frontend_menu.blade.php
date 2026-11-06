{{-- Public navbar entry: only where the application opened the AI page to everyone (ai.overview_public) --}}
@if(config('ai.overview_public'))
    <li class="nav-item">
        <a href="{{ route_lang('ai.develop') }}" class="nav-link {{ request()->routeIs('ai.develop') ? 'active' : '' }}">
            <i class="fas fa-robot me-1"></i>{{ __('AI') }}
        </a>
    </li>
@endif
