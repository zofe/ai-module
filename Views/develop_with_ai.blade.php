@php
    $money = fn (float $v) => number_format($v, $v < 1 ? 4 : 2) . ' $';
    $runtimeState = ! $runtime['configured'] ? 'secondary' : ($runtime['budget'] > 0 && $runtime['today']['cost'] >= $runtime['budget'] ? 'danger' : 'success');
    $s = $report['summary'] ?? null;
    if ($s) {
        $badge = fn (string $status) => ['ok' => 'success', 'warn' => 'warning', 'missing' => 'danger'][$status] ?? 'secondary';
        $agentState = $s['agent_ready'] ? 'success' : ($s['capabilities_ok'] > 0 ? 'warning' : 'danger');
        $builtState = $s['modules_total'] === 0 ? 'secondary' : ($s['modules_conventional'] === $s['modules_total'] ? 'success' : 'warning');
    }
@endphp
<div x-data="{ tab: 'app' }">
    <x-rpd::card>
        <x-slot name="buttons">
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="refresh">{{ __('Refresh') }}</button>
        </x-slot>

        @if($develop)
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item"><a href="#" class="nav-link" :class="{ active: tab === 'app' }" @click.prevent="tab = 'app'">{{ __('AI in this application') }}</a></li>
                <li class="nav-item"><a href="#" class="nav-link" :class="{ active: tab === 'dev' }" @click.prevent="tab = 'dev'">{{ __('Develop with AI') }}</a></li>
            </ul>
        @else
            <h5 class="card-title">{{ __('AI in this application') }}</h5>
        @endif

        {{-- ============ For the people who use the application ============ --}}
        <div x-show="tab === 'app'">
            @if(! $runtime['configured'])
                <p class="mb-0">{{ __('No provider configured: the chat widget and the tools are off.') }}
                    {{ __('Set :provider and its key in :env (Anthropic, OpenAI or any OpenAI-compatible API such as DeepSeek, or Ollama on your machine).', ['provider' => 'AI_PROVIDER', 'env' => '.env']) }}</p>
            @else
                <p class="text-muted mb-3">
                    {{ __('What the AI did here, what it cost, and what the assistant may read.') }}
                    {{ __('Model :model via :provider.', ['model' => $runtime['model'], 'provider' => $runtime['provider'] . ($runtime['base_url'] ? ' (' . parse_url($runtime['base_url'], PHP_URL_HOST) . ')' : '')]) }}
                </p>

                {{-- What the AI did: the activities the modules declare --}}
                <h5 class="fw-semibold mt-4 mb-3 pb-2 border-bottom">{{ __('What the AI did') }}</h5>
                @if($activities)
                    <div class="row g-3 mb-4">
                        @foreach($activities as $a)
                            <div class="col-md-4 col-lg-3">
                                <div class="border rounded p-3 h-100">
                                    <div class="small text-muted">{{ $a['label'] }}</div>
                                    <div class="fs-3 fw-semibold">{{ number_format($a['count']) }}</div>
                                    @if($a['detail'])<div class="small text-muted">{{ $a['detail'] }}</div>@endif
                                    <div class="small mt-1">
                                        @if($a['cost'] !== null)<span class="text-body">{{ $money($a['cost']) }}</span>@endif
                                        @if($a['last_at'])<span class="text-muted"> · {{ __('last') }} {{ $a['last_at'] }}</span>@endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="small text-muted mb-4">{{ __('Nothing declared yet: a module says what the AI did in it by implementing AiActivityProvider.') }}</p>
                @endif

                {{-- What it costs: the ledger --}}
                <h5 class="fw-semibold mt-4 mb-3 pb-2 border-bottom">{{ __('What it costs') }}</h5>
                @if($runtime['ledger'])
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100 border-{{ $runtimeState }}">
                                <div class="small text-muted">{{ __('Today') }}</div>
                                <div class="fs-4 fw-semibold">{{ $money($runtime['today']['cost']) }}</div>
                                <div class="small text-muted">{{ $runtime['budget'] > 0 ? __('of :budget a day', ['budget' => $money($runtime['budget'])]) : __('no daily budget (AI_DAILY_BUDGET)') }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">{{ __('This month') }}</div>
                                <div class="fs-4 fw-semibold">{{ $money($runtime['month']['cost']) }}</div>
                                <div class="small text-muted">{{ number_format($runtime['month']['requests']) }} {{ __('requests') }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">{{ $runtime['all_time']['since'] ? __('Since :date', ['date' => $runtime['all_time']['since']]) : __('Since the start') }}</div>
                                <div class="fs-4 fw-semibold">{{ $money($runtime['all_time']['cost']) }}</div>
                                <div class="small text-muted">{{ number_format($runtime['all_time']['requests']) }} {{ __('requests') }}</div>
                            </div>
                        </div>
                    </div>
                    @if($runtime['contexts'])
                        <p class="small mb-3"><span class="text-muted">{{ __('This month, by context') }}:</span>
                            @foreach($runtime['contexts'] as $c)
                                <span class="text-nowrap ms-1">{{ $c['context'] ?? '–' }} <strong>{{ $money($c['cost']) }}</strong>{{ $loop->last ? '' : ' ·' }}</span>
                            @endforeach
                        </p>
                    @endif
                @else
                    <p class="small text-muted">{{ __('The ledger is off: run the migrations (ai_usage) to keep the spend per month and per context.') }}</p>
                @endif
                @php $week = ['cost' => array_sum(array_column($runtime['days'], 'cost')), 'requests' => array_sum(array_column($runtime['days'], 'requests'))]; @endphp
                <details class="mb-4">
                    {{-- The sum is readable with the details closed: the table inside is the breakdown --}}
                    <summary class="small">
                        <span class="text-muted">{{ __('Last :days days', ['days' => count($runtime['days'])]) }}:</span>
                        <strong>{{ $money($week['cost']) }}</strong>
                        <span class="text-muted">· {{ number_format($week['requests']) }} {{ __('requests') }} · {{ __('prices') }} {{ $runtime['prices'][0] }} / {{ $runtime['prices'][1] }} {{ __('$ per million tokens (input / output)') }}</span>
                    </summary>
                    <table class="table table-sm mt-2 mb-1">
                        <thead><tr><th>{{ __('Day') }}</th><th class="text-end">{{ __('Requests') }}</th><th class="text-end">{{ __('Input tokens') }}</th><th class="text-end">{{ __('Output tokens') }}</th><th class="text-end">{{ __('Cost') }}</th></tr></thead>
                        <tbody>
                        {{-- A day without calls is not a row: the table says when the AI worked --}}
                        @foreach(array_filter($runtime['days'], fn ($d) => $d['requests'] > 0) as $day)
                            <tr><td>{{ $day['day'] }}</td><td class="text-end">{{ number_format($day['requests']) }}</td><td class="text-end">{{ number_format($day['input']) }}</td><td class="text-end">{{ number_format($day['output']) }}</td><td class="text-end">{{ $money($day['cost']) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </details>

                {{-- What the assistant may read --}}
                <h5 class="fw-semibold mt-4 mb-3 pb-2 border-bottom">{{ __('What the assistant may read') }}</h5>
                @if($runtime['tools'])
                    <ul class="list-unstyled mb-1">
                        @foreach($runtime['tools'] as $tool)
                            <li class="mb-1"><code class="small">{{ $tool['name'] }}</code> <span class="small text-muted">— {{ $tool['description'] }}</span></li>
                        @endforeach
                    </ul>
                @else
                    <p class="small text-muted mb-1">{{ __('none registered (modules implement AiToolProvider)') }}</p>
                @endif
                @if($runtime['tools_withheld'])
                    <p class="small text-muted mb-1">{{ __('Registered but out of the perimeter (:setting): :tools', ['setting' => 'ai.widget.tools', 'tools' => implode(', ', $runtime['tools_withheld'])]) }}</p>
                @endif
                <p class="small text-muted mb-0">
                    {{ __('Widget') }}: {{ $runtime['widget'] ? __('enabled') : __('disabled') }}, {{ __('mode') }} {{ $runtime['mode'] }}
                </p>
            @endif
        </div>

        {{-- What the assistant knows: the documents, readable here --}}
        <div x-show="tab === 'app'">
            @if($runtime['configured'] && $runtime['knowledge_docs'])
                @php $knowledgeLength = array_sum(array_map(fn ($d) => mb_strlen($d['text']), $runtime['knowledge_docs'])); @endphp
                <h5 class="fw-semibold mt-4 mb-3 pb-2 border-bottom">{{ __('What it knows') }}</h5>
                <p class="small text-muted">
                    {{ __('These documents are added to every question. They are files of the application: to correct what the assistant knows, edit them.') }}
                    {{ __(':chars characters in :docs documents, limit :max.', ['chars' => number_format($knowledgeLength), 'docs' => count($runtime['knowledge_docs']), 'max' => number_format($runtime['knowledge_max'])]) }}
                    @if($knowledgeLength > $runtime['knowledge_max'])<span class="text-danger">{{ __('Over the limit: the end is cut off.') }}</span>@endif
                </p>
                @foreach($runtime['knowledge_docs'] as $doc)
                    <details class="border rounded p-3 mb-2">
                        <summary><strong>{{ $doc['title'] }}</strong> <code class="small text-muted ms-2">{{ $doc['file'] }}</code> <span class="small text-muted">· {{ number_format(mb_strlen($doc['text'])) }}</span></summary>
                        <div class="rpd-markdown mt-3 small" style="max-height: 70vh; overflow: auto">{!! $doc['html'] !!}</div>
                    </details>
                @endforeach
            @endif
        </div>

        {{-- ============ For the people who develop it ============ --}}
        @if($develop && $s)
        <div x-show="tab === 'dev'" x-cloak>
            <p class="text-muted mb-3">
                {{ __('Rapyd Admin teaches your coding assistant (Claude Code, Codex, Cursor…) how this application is built, and writes the boilerplate itself.') }}
                {{ __('Nothing here calls a provider.') }}
            </p>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 border-{{ $agentState }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong>{{ __('Your agent') }}</strong>
                            <span class="badge bg-{{ $agentState }}">{{ $s['agent_ready'] ? __('ready') : $s['capabilities_ok'] . ' / ' . $s['capabilities_total'] }}</span>
                        </div>
                        <small class="text-muted">{{ $s['agent_ready'] ? __('Knows Rapyd Admin, Laravel and Livewire as this app uses them.') : __('Something to unlock below.') }}</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 border-{{ $builtState }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong>{{ __('Built here') }}</strong>
                            <span class="badge bg-{{ $builtState }}">{{ $s['modules_conventional'] }} / {{ $s['modules_total'] }}</span>
                        </div>
                        <small class="text-muted">{{ __('Modules in app/Modules that follow the conventions: authorized pages, permissions, a test.') }}</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 border-{{ $s['generated_runs'] ? 'success' : 'secondary' }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong>{{ __('Boilerplate written for you') }}</strong>
                            <span class="badge bg-{{ $s['generated_runs'] ? 'success' : 'secondary' }}">~{{ number_format($s['generated_tokens']) }} {{ __('tokens') }}</span>
                        </div>
                        <small class="text-muted">{{ __(':files files in :runs runs of rpd:make: code the model did not have to generate (est.).', ['files' => $s['generated_files'], 'runs' => $s['generated_runs']]) }}</small>
                    </div>
                </div>
            </div>

            <h6>{{ __('What your agent can do here') }}</h6>
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

            <details class="mb-4">
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

            <h6>{{ __('Built in this project') }}</h6>
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
        </div>
        @endif
    </x-rpd::card>
</div>
