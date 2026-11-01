@props(['showList' => false])

{{--
    Row of a task, `entry` ({ task, state }) comes from the surrounding x-for. The round button
    completes it (undoable), the row opens the details, more actions via swipe, long press or "…".
--}}
<x-swipe-row actions="taskActions(entry.task)">
    <button type="button" class="check" role="checkbox" aria-checked="false"
        :class="{
            'border-red-500': entry.state.status === 'overdue',
            'border-amber-500': ['due', 'open'].includes(entry.state.status),
        }"
        @click="check(entry.task)" :aria-label="$t('Mark “:task” as done', { task: entry.task.title })">
        <svg viewBox="0 0 24 24" class="size-4 text-stone-300" aria-hidden="true"><path d="M5 12l5 5 9-10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <a :href="`#/task/${entry.task.id}`" class="flex min-w-0 flex-1 items-center gap-3 py-3 pr-3" draggable="false">
        <span x-show="entry.task.icon" class="w-7 shrink-0 text-center text-xl" aria-hidden="true" x-text="entry.task.icon"></span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium" x-text="entry.task.title"></span>
            <span class="flex min-w-0 items-center gap-1.5 text-xs">
                <span class="truncate" :class="dueClass(entry.state)" x-text="dueLabel(entry.task, entry.state)"></span>
                @if ($showList)
                    <span class="truncate text-stone-500" x-text="'· ' + (listById(entry.task.task_list_id)?.name ?? '')"></span>
                @endif
                <span x-show="entry.task.checklist?.length" class="shrink-0 text-stone-500"
                    x-text="`· ☑ ${entry.task.checklist.filter((step) => step.done).length}/${entry.task.checklist.length}`"></span>
            </span>
        </span>
        <span x-show="listById(entry.task.task_list_id)?.ranking_enabled" class="shrink-0 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-sky-800"
            :title="$t('Points')" x-text="`${entry.task.points} ★`"></span>
    </a>
</x-swipe-row>
