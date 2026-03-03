@php
    $steps = $getState() ?? [];

    $stepLabels = [
        'backup'          => ['icon' => '📦', 'label' => 'Backup pré-déploiement'],
        'maintenance_on'  => ['icon' => '🔧', 'label' => 'Mode maintenance ON'],
        'git_pull'        => ['icon' => '📥', 'label' => 'Git pull'],
        'commit_info'     => ['icon' => '🔖', 'label' => 'Informations du commit'],
        'composer_install'=> ['icon' => '🎼', 'label' => 'Composer install'],
        'migrations'      => ['icon' => '🗄️',  'label' => 'Migrations'],
        'cache_clear'     => ['icon' => '🧹', 'label' => 'Nettoyage caches'],
        'cache_rebuild'   => ['icon' => '🔄', 'label' => 'Reconstruction caches'],
        'permissions'     => ['icon' => '🔐', 'label' => 'Permissions'],
        'maintenance_off' => ['icon' => '✅', 'label' => 'Mode maintenance OFF'],
        'error'           => ['icon' => '❌', 'label' => 'Erreur'],
    ];

    $commitStep = collect($steps)->firstWhere('step', 'commit_info');
    $commit = $commitStep['commit'] ?? null;
@endphp

<div style="font-family: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', 'Consolas', monospace; background: #0d1117; border-radius: 10px; overflow: hidden; border: 1px solid #30363d;">

    {{-- Terminal titlebar --}}
    <div style="background: #161b22; padding: 10px 16px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #30363d;">
        <span style="width: 12px; height: 12px; background: #ff5f56; border-radius: 50%; display: inline-block;"></span>
        <span style="width: 12px; height: 12px; background: #ffbd2e; border-radius: 50%; display: inline-block;"></span>
        <span style="width: 12px; height: 12px; background: #27c93f; border-radius: 50%; display: inline-block;"></span>
        <span style="margin-left: 8px; color: #8b949e; font-size: 12px;">klassci-deploy — bash</span>
    </div>

    {{-- Commit info box --}}
    @if($commit)
    <div style="background: #161b22; border-bottom: 1px solid #30363d; padding: 14px 20px;">
        <div style="color: #58a6ff; font-size: 13px; font-weight: 700; margin-bottom: 8px;">
            ── Commit déployé ────────────────────────────────────
        </div>
        <div style="display: grid; grid-template-columns: 110px 1fr; gap: 4px 12px; font-size: 12px;">
            <span style="color: #8b949e;">Hash</span>
            <span style="color: #e3b341; letter-spacing: 0.05em;">{{ $commit['hash'] ?? '—' }}</span>

            <span style="color: #8b949e;">Auteur</span>
            <span style="color: #d2a8ff;">{{ $commit['author'] ?? '—' }}
                @if(!empty($commit['email']))
                    <span style="color: #8b949e;">&lt;{{ $commit['email'] }}&gt;</span>
                @endif
            </span>

            <span style="color: #8b949e;">Message</span>
            <span style="color: #ffffff; font-weight: 600;">{{ $commit['message'] ?? '—' }}</span>

            <span style="color: #8b949e;">Date</span>
            <span style="color: #7ee787;">{{ $commit['date'] ?? '—' }}</span>
        </div>
    </div>
    @endif

    {{-- Steps --}}
    <div style="padding: 16px 20px;">
        @if(empty($steps))
            <div style="color: #8b949e; font-size: 13px; padding: 8px 0;">
                <span style="color: #6e7681;">$</span>
                <span style="color: #8b949e; margin-left: 6px;">Aucun log de déploiement disponible.</span>
            </div>
        @else
            @foreach($steps as $step)
                @php
                    $name = $step['step'] ?? 'unknown';
                    $status = $step['status'] ?? 'ok';
                    $output = $step['output'] ?? '';
                    $durationMs = $step['duration_ms'] ?? 0;
                    $meta = $stepLabels[$name] ?? ['icon' => '•', 'label' => $name];

                    $statusColor = match($status) {
                        'ok'     => '#7ee787',
                        'failed' => '#f85149',
                        default  => '#e3b341',
                    };
                    $statusText = match($status) {
                        'ok'     => 'OK',
                        'failed' => 'FAILED',
                        default  => strtoupper($status),
                    };
                @endphp

                @if($name === 'commit_info')
                    {{-- commit_info is shown in the header box, skip inline --}}
                @else
                <div style="margin-bottom: 12px;">
                    {{-- Step header line --}}
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <span style="color: #6e7681;">$</span>
                        <span style="color: #c9d1d9; font-size: 13px; flex: 1;">
                            {{ $meta['icon'] }} <span style="color: #58a6ff; font-weight: 600;">{{ $meta['label'] }}</span>
                        </span>
                        <span style="background: {{ $statusColor }}22; color: {{ $statusColor }}; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; border: 1px solid {{ $statusColor }}44; letter-spacing: 0.05em;">
                            {{ $statusText }}
                        </span>
                        @if($durationMs > 0)
                            <span style="color: #6e7681; font-size: 11px; min-width: 60px; text-align: right;">
                                @if($durationMs >= 1000)
                                    {{ number_format($durationMs / 1000, 1) }}s
                                @else
                                    {{ $durationMs }}ms
                                @endif
                            </span>
                        @endif
                    </div>

                    {{-- Output --}}
                    @if($output && $name !== 'error')
                        <div style="background: #0d1117; border-left: 2px solid {{ $statusColor }}44; padding: 8px 12px; margin-left: 20px; border-radius: 0 4px 4px 0;">
                            <pre style="margin: 0; color: #8b949e; font-size: 11px; white-space: pre-wrap; word-break: break-all; line-height: 1.6;">{{ $output }}</pre>
                        </div>
                    @elseif($output && $name === 'error')
                        <div style="background: #1a0f0f; border-left: 2px solid #f85149; padding: 8px 12px; margin-left: 20px; border-radius: 0 4px 4px 0;">
                            <pre style="margin: 0; color: #ffa198; font-size: 11px; white-space: pre-wrap; word-break: break-all; line-height: 1.6;">{{ $output }}</pre>
                        </div>
                    @endif
                </div>
                @endif
            @endforeach
        @endif

        {{-- Prompt cursor --}}
        <div style="margin-top: 8px; color: #6e7681; font-size: 13px;">
            <span style="color: #7ee787;">klassci@deploy</span><span style="color: #6e7681;">:</span><span style="color: #58a6ff;">~</span><span style="color: #c9d1d9;"> $ </span><span style="display: inline-block; width: 8px; height: 14px; background: #c9d1d9; vertical-align: middle; animation: blink 1.2s step-end infinite;"></span>
        </div>
    </div>
</div>

<style>
@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}
</style>
