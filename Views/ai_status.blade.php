@php
    $s = $report['summary'];
    $badge = fn (string $status) => ['ok' => 'success', 'warn' => 'warning', 'missing' => 'danger'][$status] ?? 'secondary';
    $toolingState = $s['tooling_ok'] === $s['tooling_total'] ? 'success' : ($s['tooling_ok'] > 0 ? 'warning' : 'danger');
    $modulesState = $s['modules_total'] === 0 ? 'secondary' : ($s['modules_protected'] === $s['modules_total'] ? 'success' : 'warning');
    $runtimeState = ! $runtime['configured'] ? 'secondary' : ($runtime['budget'] > 0 && $runtime['today']['cost'] >= $runtime['budget'] ? 'danger' : 'success');
    $money = fn (float $v) => number_format($v, $v < 1 ? 4 : 2) . ' $';
@endphp
<div>
    <x-rpd::card title="AI readiness">
        <x-slot name="buttons">
            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="refresh">Refresh</button>
            <button type="button" class="btn btn-outline-primary btn-sm" wire:click="toggle">{{ $advanced ? 'Simple view' : 'Advanced view' }}</button>
        </x-slot>

        <p class="text-muted mb-3">
            Is this application ready to be developed with an AI coding assistant, and what does the AI inside it cost?
            Nothing here calls a provider: it reads the files of the application and the counters of the widget.
        </p>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 border-{{ $toolingState }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>Coding assistant</strong>
                        <span class="badge bg-{{ $toolingState }}">{{ $s['tooling_ok'] }} / {{ $s['tooling_total'] }}</span>
                    </div>
                    <small class="text-muted">Does the agent (Claude Code, Codex, Cursor…) know Rapyd Admin, Laravel and Livewire the way this app uses them?</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 border-{{ $modulesState }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>Your modules</strong>
                        <span class="badge bg-{{ $modulesState }}">{{ $s['modules_protected'] }} / {{ $s['modules_total'] }}</span>
                    </div>
                    <small class="text-muted">Do the modules in app/Modules follow the conventions the agent is taught: authorized pages, permissions, scoping, tests?</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 border-{{ $runtimeState }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong>AI in the app</strong>
                        <span class="badge bg-{{ $runtimeState }}">{{ $runtime['configured'] ? $money($runtime['today']['cost']) . ' today' : 'not configured' }}</span>
                    </div>
                    <small class="text-muted">The chat widget and the tools the modules give the model: provider, mode, spend against the daily budget.</small>
                </div>
            </div>
        </div>
    </x-rpd::card>

    @if(! $advanced)
        {{-- simple view: plain words and what to do --}}
        <x-rpd::card title="Coding assistant">
            @if($s['tooling_ok'] === $s['tooling_total'])
                <p class="mb-0"><span class="badge bg-success">ready</span> The guideline and the skills of Rapyd Admin are in front of the agent and current.
                    Ask it things like <em>"add a Suppliers section with a list, a detail page and a form"</em>: it will generate the module, the permissions and the tests the Rapyd way.</p>
            @else
                <p>Some of what the agent needs is missing or older than the installed version. Run, in the root of the application:</p>
                <ul class="mb-2">
                    @foreach($fixes as $fix)
                        <li><code>{{ $fix }}</code></li>
                    @endforeach
                </ul>
                <small class="text-muted">The guideline explains Rapyd Admin to the agent; the skills are step-by-step procedures it follows (create a module, design a workflow); MCP gives it live access to the schema, the logs and the docs. Then come back and press Refresh.</small>
            @endif
        </x-rpd::card>

        <x-rpd::card title="Your modules">
            @if($s['modules_total'] === 0)
                <p class="mb-0">No module in <code>app/Modules</code> yet. The first one is one command away:
                    <code>php artisan rpd:make Things Thing --module=Name --fields="name,active:boolean"</code>, or ask the agent.</p>
            @elseif($s['modules_protected'] === $s['modules_total'])
                <p class="mb-0"><span class="badge bg-success">ok</span> Every page of your {{ $s['modules_total'] }} {{ Str::plural('module', $s['modules_total']) }} checks who is logged in and what they may do.</p>
            @else
                <p>Some pages can be opened without a permission check:</p>
                <ul class="mb-2">
                    @foreach($report['modules'] as $m)
                        @foreach($m['unprotected'] as $component)
                            <li><code>app/Modules/{{ $m['name'] }}/Livewire/{{ $component }}.php</code></li>
                        @endforeach
                    @endforeach
                </ul>
                <small class="text-muted">Add <code>use Authorize</code> and <code>$this->authorize('admin|view …')</code> in <code>booted()</code>, or ask the agent to "protect the pages of the {{ $report['modules'][0]['name'] }} module".</small>
            @endif
        </x-rpd::card>

        <x-rpd::card title="AI in the app">
            @if(! $runtime['configured'])
                <p class="mb-0">No provider configured: the chat widget and the tools are off. Set <code>AI_PROVIDER</code> and its key in <code>.env</code> (Anthropic, OpenAI or any OpenAI-compatible API such as DeepSeek, or Ollama on your machine).</p>
            @else
                <p class="mb-1">Provider <strong>{{ $runtime['provider'] }}</strong>, model <strong>{{ $runtime['model'] }}</strong>@if($runtime['base_url']) at {{ $runtime['base_url'] }}@endif.
                    Widget {{ $runtime['widget'] ? 'on' : 'off' }}, mode <strong>{{ $runtime['mode'] }}</strong>
                    ({{ $runtime['mode'] === 'customer' ? 'text only, for a public site' : 'can use the tools of the modules on the application data' }}).</p>
                <p class="mb-0">Today: {{ $runtime['today']['requests'] }} requests, {{ $money($runtime['today']['cost']) }}
                    @if($runtime['budget'] > 0) of a {{ $money($runtime['budget']) }} daily budget @else and no daily budget (set <code>AI_DAILY_BUDGET</code> on a public site) @endif.
                    Tools available to the model: {{ $runtime['tools'] ? implode(', ', $runtime['tools']) : 'none yet' }}.</p>
            @endif
        </x-rpd::card>
    @else
        {{-- advanced view: every row --}}
        <x-rpd::card title="Coding assistant">
            <table class="table table-sm mb-3">
                <thead><tr><th></th><th>Check</th><th>Detail</th><th>Fix</th></tr></thead>
                <tbody>
                @foreach($report['tooling'] as $row)
                    <tr>
                        <td><span class="badge bg-{{ $badge($row['status']) }}">{{ $row['status'] }}</span></td>
                        <td>{{ $row['label'] }}</td>
                        <td>{{ $row['detail'] }}</td>
                        <td>@if($row['fix'])<code>{{ $row['fix'] }}</code>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <h6>Context the agent loads (estimate, characters / 4)</h6>
            <p class="text-muted small">Resident in every session: one memory file (AGENTS.md or CLAUDE.md) and the skill descriptions. Skill bodies are read only when a skill is used.</p>
            <table class="table table-sm mb-0">
                <tbody>
                @foreach($report['context']['files'] as $file => $tokens)
                    <tr><td>{{ $file }}</td><td class="text-end">{{ number_format($tokens) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Resident per session</td><td class="text-end">{{ number_format($report['context']['resident']) }}</td></tr>
                <tr><td>Skills, on demand</td><td class="text-end">{{ number_format($report['context']['on_demand']) }}</td></tr>
                </tbody>
            </table>
        </x-rpd::card>

        <x-rpd::card title="Your modules">
            @if($s['modules_total'] === 0)
                <p class="mb-0 text-muted">No module in app/Modules.</p>
            @else
                <table class="table table-sm mb-0">
                    <thead><tr><th>Module</th><th>Pages</th><th>Workflows</th><th>Permissions</th><th>Authorizations</th><th>Limits</th><th>Tests</th><th>Without Authorize</th></tr></thead>
                    <tbody>
                    @foreach($report['modules'] as $m)
                        <tr>
                            <td>{{ $m['name'] }}</td>
                            <td>{{ $m['pages'] }} <small class="text-muted">/ {{ $m['components'] }} components</small></td>
                            <td>{{ $m['workflows'] ? implode(', ', $m['workflows']) : '–' }}</td>
                            <td>{{ $m['permissions'] ? implode(', ', $m['permissions']) : '–' }}</td>
                            <td>{{ $m['authorizations'] }}</td>
                            <td>{{ $m['limits'] }}</td>
                            <td>{{ $m['tests'] }}</td>
                            <td>@if($m['unprotected'])<span class="badge bg-danger">{{ implode(', ', $m['unprotected']) }}</span>@else <span class="badge bg-success">none</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-rpd::card>

        <x-rpd::card title="AI in the app">
            <dl class="row mb-3">
                <dt class="col-sm-3">Provider</dt><dd class="col-sm-9">{{ $runtime['provider'] }} {{ $runtime['configured'] ? '' : '(no key)' }}@if($runtime['base_url']) · {{ $runtime['base_url'] }}@endif</dd>
                <dt class="col-sm-3">Model</dt><dd class="col-sm-9">{{ $runtime['model'] }}</dd>
                <dt class="col-sm-3">Widget</dt><dd class="col-sm-9">{{ $runtime['widget'] ? 'enabled' : 'disabled' }}, mode {{ $runtime['mode'] }}@if($runtime['knowledge']), knowledge {{ $runtime['knowledge'] }}@endif</dd>
                <dt class="col-sm-3">Tools</dt><dd class="col-sm-9">{{ $runtime['tools'] ? implode(', ', $runtime['tools']) : 'none registered (modules implement AiToolProvider)' }}</dd>
                <dt class="col-sm-3">Prices</dt><dd class="col-sm-9">{{ $runtime['prices'][0] }} / {{ $runtime['prices'][1] }} $ per million tokens (input / output)</dd>
                <dt class="col-sm-3">Daily budget</dt><dd class="col-sm-9">{{ $runtime['budget'] > 0 ? $money($runtime['budget']) : 'none' }}</dd>
            </dl>
            <table class="table table-sm mb-0">
                <thead><tr><th>Day</th><th class="text-end">Requests</th><th class="text-end">Input tokens</th><th class="text-end">Output tokens</th><th class="text-end">Cost</th></tr></thead>
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
        </x-rpd::card>
    @endif
</div>
