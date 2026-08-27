@php $aiKeySet = (bool) config('ai.' . config('ai.provider') . '.key'); @endphp
<div
    x-data="{ open: false }"
    style="position: fixed; bottom: 24px; right: 24px; z-index: 1050; font-size: 14px;"
>
    {{-- Toggle button --}}
    <button
        x-on:click="open = !open"
        class="btn btn-primary rounded-circle shadow-lg d-flex align-items-center justify-content-center"
        style="width: 52px; height: 52px;"
        title="{{ $mode === 'operator' ? 'AI Assistant' : 'Support' }}"
    >
        <i class="fas" :class="open ? 'fa-times' : 'fa-robot'"></i>
    </button>

    {{-- Chat panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-cloak
        class="card shadow-lg border-0"
        style="
            position: absolute; bottom: 64px; right: 0;
            width: 360px; height: 480px;
            display: flex; flex-direction: column;
            border-radius: 12px; overflow: hidden;
        "
    >
        {{-- Header --}}
        <div class="card-header d-flex align-items-center justify-content-between py-2 px-3 bg-primary text-white">
            <span class="fw-semibold">
                <i class="fas fa-robot me-1"></i>
                {{ $mode === 'operator' ? 'AI Assistant' : 'Support' }}
            </span>
            @if(count($messages) > 0)
            <button wire:click="clear" class="btn btn-sm btn-link text-white p-0" title="Clear">
                <i class="fas fa-trash-alt fa-xs"></i>
            </button>
            @endif
        </div>

        {{-- Messages --}}
        <div
            class="flex-grow-1 overflow-auto p-3"
            style="background: #f8f9fa;"
            x-ref="messages"
            x-effect="$nextTick(() => { $refs.messages.scrollTop = $refs.messages.scrollHeight })"
        >
            @if(!$aiKeySet)
                <div class="text-center text-muted mt-4 px-3" style="font-size: 12px;">
                    <i class="fas fa-key fa-2x mb-2 d-block opacity-40"></i>
                    AI provider not configured.<br>
                    Set <code>AI_OPENAI_KEY</code> or <code>ANTHROPIC_API_KEY</code> in <code>.env</code>.
                </div>
            @elseif(empty($messages))
                <div class="text-center text-muted mt-4" style="font-size: 13px;">
                    <i class="fas fa-robot fa-2x mb-2 d-block opacity-50"></i>
                    @if($mode === 'operator')
                        Ask me about your application data,<br>logs, users, or anything else.
                    @else
                        How can I help you today?
                    @endif
                </div>
            @endif

            @foreach($messages as $message)
                @if($message['role'] === 'user')
                    <div class="d-flex justify-content-end mb-2">
                        <div class="px-3 py-2 rounded-3 text-white bg-primary" style="max-width: 80%; white-space: pre-wrap; word-break: break-word;">{{ $message['content'] }}</div>
                    </div>
                @else
                    <div class="d-flex justify-content-start mb-2">
                        <div
                            class="px-3 py-2 rounded-3 {{ !empty($message['error']) ? 'bg-danger text-white' : 'bg-white border' }}"
                            style="max-width: 85%; white-space: pre-wrap; word-break: break-word; line-height: 1.5;"
                        >{{ $message['content'] }}</div>
                    </div>
                @endif
            @endforeach

            @if($loading)
                <div class="d-flex justify-content-start mb-2">
                    <div class="px-3 py-2 rounded-3 bg-white border text-muted" style="font-size: 13px;">
                        <span class="spinner-border spinner-border-sm me-1" style="width: 10px; height: 10px;"></span>
                        Thinking...
                    </div>
                </div>
            @endif
        </div>

        {{-- Input --}}
        @if($aiKeySet)
        <div class="card-footer p-2 bg-white border-top">
            <form wire:submit="send" class="d-flex gap-2">
                <input
                    wire:model="input"
                    type="text"
                    class="form-control form-control-sm"
                    placeholder="{{ $mode === 'operator' ? 'Ask about your data...' : 'Type a message...' }}"
                    :disabled="$wire.loading"
                    autocomplete="off"
                >
                <button
                    type="submit"
                    class="btn btn-primary btn-sm px-3"
                    :disabled="$wire.loading || !$wire.input.trim()"
                >
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
        @endif
    </div>
</div>
