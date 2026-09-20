@php
    $s = $report['summary'];
    $badge = fn (string $status) => ['ok' => 'success', 'warn' => 'warning', 'missing' => 'danger'][$status] ?? 'secondary';
    $agentState = $s['agent_ready'] ? 'success' : ($s['capabilities_ok'] > 0 ? 'warning' : 'danger');
    $builtState = $s['modules_total'] === 0 ? 'secondary' : ($s['modules_conventional'] === $s['modules_total'] ? 'success' : 'warning');
    $runtimeState = ! $runtime['configured'] ? 'secondary' : ($runtime['budget'] > 0 && $runtime['today']['cost'] >= $runtime['budget'] ? 'danger' : 'success');
    $money = fn (float $v) => number_format($v, $v < 1 ? 4 : 2) . ' $';
@endphp
<div>
    <x-rpd::card title="Develop with AI">
        <x-slot name="buttons">
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="refresh">{{ __('Refresh') }}</button>
        </x-slot>

        <p class="text-muted mb-3">
            {{ __('Rapyd Admin teaches your coding assistant (Claude Code, Codex, Cursor…) how this application is built, and writes the boilerplate itself.') }}
            {{ __('This page shows what the agent can do here, what was built with it, and what the AI inside the application costs.') }}
            {{ __('Nothing here calls a provider.') }}
        </p>

        <div class="row g-3">
            <div class="col-md-3">
                <div class="border rounded p-3 h-100 border-{{ $agentState }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>{{ __('Your agent') }}</strong>
                        <span class="badge bg-{{ $agentState }}">{{ $s['agent_ready'] ? __('ready') : $s['capabilities_ok'] . ' / ' . $s['capabilities_total'] }}</span>
                    </div>
                    <small class="text-muted">{{ $s['agent_ready'] ? __('Knows Rapyd Admin, Laravel and Livewire as this app uses them.') : __('Something to unlock below.') }}</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100 border-{{ $builtState }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>{{ __('Built here') }}</strong>
                        <span class="badge bg-{{ $builtState }}">{{ $s['modules_conventional'] }} / {{ $s['modules_total'] }}</span>
                    </div>
                    <small class="text-muted">{{ __('Modules in app/Modules that follow the conventions: authorized pages, permissions, a test.') }}</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100 border-{{ $s['generated_runs'] ? 'success' : 'secondary' }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>{{ __('Boilerplate written for you') }}</strong>
                        <span class="badge bg-{{ $s['generated_runs'] ? 'success' : 'secondary' }}">~{{ number_format($s['generated_tokens']) }} {{ __('tokens') }}</span>
                    </div>
                    <small class="text-muted">{{ __(':files files in :runs runs of rpd:make: code the model did not have to generate (est.).', ['files' => $s['generated_files'], 'runs' => $s['generated_runs']]) }}</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3 h-100 border-{{ $runtimeState }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>{{ __('AI in the app') }}</strong>
                        <span class="badge bg-{{ $runtimeState }}">{{ $runtime['configured'] ? $money($runtime['today']['cost']) . ' ' . __('today') : __('not configured') }}</span>
                    </div>
                    <small class="text-muted">{{ __('The chat widget and the tools the modules give the model; spend against the daily budget.') }}</small>
                </div>
            </div>
        </div>
    </x-rpd::card>

    <x-rpd::card title="What your agent can do here">
        @if($unlocks)
            <p class="mb-2">{{ __('To unlock the rest, in the root of the application:') }}</p>
            <ul class="mb-3">
                @foreach($unlocks as $fix)
                    <li><code>{{ $fix }}</code></li>
                @endforeach
            </ul>
        @endif
        <div class="row g-2 mb-3">
            @foreach($report['capabilities'] as $row)
                <div class="col-md-6">
                    <div class="d-flex align-items-start gap-2 border rounded p-2 h-100">
                        <span class="badge bg-{{ $badge($row['status']) }} mt-1">{{ $row['status'] === 'ok' ? '✔' : ($row['status'] === 'warn' ? '!' : '✘') }}</span>
                        <div>
                            <strong>{{ __($row['label']) }}</strong>
                            <div class="small text-muted">{{ $row['detail'] }}</div>
                            @if($row['fix'])<div class="small"><code>{{ $row['fix'] }}</code></div>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <h6>{{ __('Generators the agent calls instead of writing the code') }}</h6>
        <table class="table table-sm mb-3">
            <tbody>
            @foreach($report['generators'] as $g)
                <tr><td class="text-nowrap"><code>{{ $g['command'] }}</code></td><td class="small text-muted">{{ $g['writes'] }}</td></tr>
            @endforeach
            </tbody>
        </table>

        <details>
            <summary class="small text-muted">{{ __('Context the agent loads per session: ~:resident tokens resident, ~:demand on demand (estimate, characters / 4)', ['resident' => number_format($report['context']['resident']), 'demand' => number_format($report['context']['on_demand'])]) }}</summary>
            <table class="table table-sm mt-2 mb-0">
                <tbody>
                @foreach($report['context']['files'] as $file => $tokens)
                    <tr><td>{{ $file }}</td><td class="text-end">{{ number_format($tokens) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <small class="text-muted">{{ __('One memory file (AGENTS.md or CLAUDE.md) and the skill descriptions are resident; skill bodies are read only when a skill is used.') }}</small>
        </details>
    </x-rpd::card>

    <x-rpd::card title="Built in this project">
        @if($s['modules_total'] === 0)
            <p class="mb-0">{{ __('No module in app/Modules yet. Give your agent the first prompt below, or run') }}
                <code>{{ $report['generators'][0]['command'] }}</code>.</p>
        @else
            <table class="table table-sm mb-2">
                <thead><tr><th>{{ __('Module') }}</th><th>{{ __('Generated') }}</th><th>{{ __('Pages') }}</th><th>{{ __('Authorized') }}</th><th>{{ __('Permissions') }}</th><th>{{ __('Limits') }}</th><th>{{ __('Tests') }}</th><th>{{ __('Workflows') }}</th></tr></thead>
                <tbody>
                @foreach($report['modules'] as $m)
                    <tr>
                        <td><strong>{{ $m['name'] }}</strong></td>
                        <td class="small">
                            @if($m['generated'])
                                {{ $m['generated']['at'] }}<br><span class="text-muted">{{ __(':files files, ~:tokens tokens', ['files' => $m['generated']['files'], 'tokens' => number_format($m['generated']['tokens'])]) }}</span>
                            @else
                                <span class="text-muted">{{ __('by hand') }}</span>
                            @endif
                        </td>
                        <td>{{ $m['pages'] }} <small class="text-muted">/ {{ $m['components'] }}</small></td>
                        <td>@if($m['unprotected'])<span class="badge bg-danger" title="{{ implode(', ', $m['unprotected']) }}">{{ count($m['unprotected']) }} {{ __('without Authorize') }}</span>@else<span class="badge bg-success">✔</span>@endif</td>
                        <td>@if($m['permissions'])<span class="badge bg-success">{{ count($m['permissions']) }}</span>@else<span class="badge bg-warning">{{ __('none') }}</span>@endif</td>
                        <td>@if($m['limits'])<span class="badge bg-success">{{ $m['limits'] }}</span>@else<span class="text-muted">–</span>@endif</td>
                        <td>@if($m['tests'])<span class="badge bg-success">{{ $m['tests'] }}</span>@else<span class="badge bg-warning">{{ __('none') }}</span>@endif</td>
                        <td>{{ $m['workflows'] ? implode(', ', $m['workflows']) : '–' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <small class="text-muted">{{ __('A module follows the conventions when every page authorizes, its permissions are declared in config.php and a test mentions it.') }} {{ __('Ask the agent to "protect the pages of the X module" or "add a feature test for X" for the rest.') }}</small>
        @endif
    </x-rpd::card>

    <x-rpd::card title="Try it: prompts for your agent">
        <p class="text-muted small">{{ __('Copy one into Claude Code, Codex or Cursor opened in the root of this application.') }} {{ __('The ones that need a module you have not installed say so.') }}</p>
        <div class="row g-3">
            @foreach($report['prompts'] as $p)
                <div class="col-md-6" x-data="{ copied: false }">
                    <div class="border rounded p-3 h-100 {{ $p['available'] ? '' : 'opacity-75' }}">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <strong>{{ __($p['title']) }}</strong>
                            @if($p['available'])
                                <button type="button" class="btn btn-outline-primary btn-sm" @click="navigator.clipboard.writeText(@js($p['prompt'])); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-show="!copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                            @else
                                <span class="badge bg-secondary">{{ __('needs') }} {{ implode(', ', $p['missing']) }}</span>
                            @endif
                        </div>
                        <blockquote class="mb-1 small fst-italic">"{{ $p['prompt'] }}"</blockquote>
                        <small class="text-muted">{{ __('Produces:') }} {{ $p['produces'] }}</small>
                    </div>
                </div>
            @endforeach
        </div>
    </x-rpd::card>

    <x-rpd::card title="AI in the app">
        @if(! $runtime['configured'])
            <p class="mb-0">{{ __('No provider configured: the chat widget and the tools are off.') }}
                {{ __('Set :provider and its key in :env (Anthropic, OpenAI or any OpenAI-compatible API such as DeepSeek, or Ollama on your machine).', ['provider' => 'AI_PROVIDER', 'env' => '.env']) }}</p>
        @else
            <dl class="row mb-3">
                <dt class="col-sm-3">{{ __('Provider') }}</dt><dd class="col-sm-9">{{ $runtime['provider'] }}@if($runtime['base_url']) · {{ $runtime['base_url'] }}@endif</dd>
                <dt class="col-sm-3">{{ __('Model') }}</dt><dd class="col-sm-9">{{ $runtime['model'] }}</dd>
                <dt class="col-sm-3">{{ __('Widget') }}</dt><dd class="col-sm-9">{{ $runtime['widget'] ? __('enabled') : __('disabled') }}, {{ __('mode') }} {{ $runtime['mode'] }} ({{ $runtime['mode'] === 'customer' ? __('text only, for a public site') : __('can use the tools of the modules on the application data') }})@if($runtime['knowledge']), {{ __('knowledge') }} {{ $runtime['knowledge'] }}@endif</dd>
                <dt class="col-sm-3">{{ __('Tools') }}</dt><dd class="col-sm-9">{{ $runtime['tools'] ? implode(', ', $runtime['tools']) : __('none registered (modules implement AiToolProvider)') }}</dd>
                <dt class="col-sm-3">{{ __('Prices') }}</dt><dd class="col-sm-9">{{ $runtime['prices'][0] }} / {{ $runtime['prices'][1] }} {{ __('$ per million tokens (input / output)') }}</dd>
                <dt class="col-sm-3">{{ __('Daily budget') }}</dt><dd class="col-sm-9">{{ $runtime['budget'] > 0 ? $money($runtime['budget']) : __('none (set AI_DAILY_BUDGET on a public site)') }}</dd>
            </dl>
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('Day') }}</th><th class="text-end">{{ __('Requests') }}</th><th class="text-end">{{ __('Input tokens') }}</th><th class="text-end">{{ __('Output tokens') }}</th><th class="text-end">{{ __('Cost') }}</th></tr></thead>
                <tbody>
                @foreach($runtime['days'] as $day)
                    <tr>
                        <td>{{ $day['day'] }}</td>
                        <td class="text-end">{{ number_format($day['requests']) }}</td>
                        <td class="text-end">{{ number_format($day['input']) }}</td>
                        <td class="text-end">{{ number_format($day['output']) }}</td>
                        <td class="text-end">{{ $money($day['cost']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-rpd::card>
</div>
