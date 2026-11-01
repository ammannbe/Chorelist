@props(['list', 'task' => 'null'])

{{-- Timeline grouped by day: completions, changes and missed dates ($list/$task: JS expressions). --}}
<div class="space-y-4">
    <p x-show="!historyDays({{ $list }}, {{ $task }}).length" class="text-sm text-stone-500" x-text="$t('Nothing yet.')"></p>
    <template x-for="day in historyDays({{ $list }}, {{ $task }})" :key="day.day">
        <div>
            <h3 class="mb-1 text-xs font-medium text-stone-500 uppercase" x-text="day.day === today ? $t('Today') : date(day.day, { weekday: 'long', day: 'numeric', month: 'long' })"></h3>
            <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white text-sm">
                <template x-for="entry in day.entries" :key="entry.id">
                    <li class="flex items-start gap-3 px-3 py-2">
                        <span class="w-5 shrink-0 text-center" aria-hidden="true"
                            x-text="{ done: '✅', extra: '⭐', skipped: '⏭️', missed: '❌', activity: '✏️' }[entry.kind]"></span>
                        <span class="min-w-0 flex-1 break-words">
                            <span class="block" :class="entry.kind === 'missed' && 'text-red-700'" x-text="entry.text"></span>
                            <span x-show="entry.who" class="block text-xs text-stone-500" x-text="entry.who"></span>
                        </span>
                    </li>
                </template>
            </ul>
        </div>
    </template>
</div>
