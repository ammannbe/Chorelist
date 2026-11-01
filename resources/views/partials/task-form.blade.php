{{-- New task (#/new/<list>) or changes (#/edit/<task>), see resources/js/schedule.js toSchedule(). --}}
<section x-show="['new', 'edit'].includes(route.view)">
    <template x-if="form">
        <form @submit.prevent="saveForm()" class="space-y-5">
            <div class="flex gap-2">
                <x-icon-picker model="form.icon" />
                <input x-model="form.title" class="field min-w-0 flex-1" required maxlength="255" :placeholder="$t('Title, e.g. Clean the bathroom')" :aria-label="$t('Title')">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label class="block">
                    <span class="label" x-text="$t('List')"></span>
                    <select x-model="form.task_list_id" class="field w-full" required>
                        <template x-for="each in lists" :key="each.id">
                            <option :value="each.id" x-text="each.name" :selected="each.id === form.task_list_id"></option>
                        </template>
                    </select>
                </label>
                {{-- Category with suggestions: the ones already used in the same list --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @focusin="open = true" @keydown.escape="open = false">
                    <label for="task-category" class="label" x-text="$t('Category (optional)')"></label>
                    <input id="task-category" x-model="form.category" class="field w-full" maxlength="100" autocomplete="off" :placeholder="$t('e.g. Kitchen')"
                        @input="open = true" :aria-expanded="open && categories.length > 0">
                    <ul x-show="open && categories.length" x-transition class="absolute top-full right-0 left-0 z-20 mt-1 max-h-48 overflow-y-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg">
                        <template x-for="category in categories" :key="category">
                            <li><button type="button" class="block w-full px-3 py-2 text-left hover:bg-stone-100" @click="form.category = category; open = false" x-text="category"></button></li>
                        </template>
                    </ul>
                </div>
            </div>

            <fieldset>
                <legend class="label" x-text="$t('Points')"></legend>
                <div class="flex gap-1.5">
                    <template x-for="points in [1, 2, 3, 4, 5]" :key="points">
                        <button type="button" class="grid h-10 flex-1 place-items-center rounded-lg border font-semibold"
                            :class="Number(form.points) === points ? 'border-sky-700 bg-sky-700 text-white' : 'border-stone-300 bg-white'"
                            :aria-pressed="Number(form.points) === points" @click="form.points = points" x-text="`${points} ★`"></button>
                    </template>
                </div>
                <p class="mt-1 text-xs text-stone-500" x-text="$t('For the ranking: 1 = quick and easy, 5 = long or unpopular.')"></p>
            </fieldset>

            {{-- Schedule --}}
            <fieldset class="space-y-3 rounded-lg border border-stone-200 bg-white p-4">
                <legend class="px-1 text-sm font-medium text-stone-600" x-text="$t('When')"></legend>
                <label class="block">
                    <span class="label" x-text="$t('Repeat')"></span>
                    <select x-model="form.repeat" class="field w-full">
                        <option value="once" x-text="$t('Once')"></option>
                        <option value="daily" x-text="$t('Daily')"></option>
                        <option value="weekly" x-text="$t('Weekly')"></option>
                        <option value="monthly" x-text="$t('Monthly')"></option>
                        <option value="yearly" x-text="$t('Yearly')"></option>
                        <option value="after_completion" x-text="$t('Some time after it was done')"></option>
                        <option value="on_demand" x-text="$t('When needed (no date)')"></option>
                    </select>
                </label>

                <div x-show="['daily', 'weekly', 'monthly', 'yearly', 'after_completion'].includes(form.repeat)" class="flex items-center gap-2">
                    <span x-text="$t('Every')"></span>
                    <input x-model.number="form.interval" type="number" min="1" max="999" inputmode="numeric" class="field w-20" :aria-label="$t('Interval')">
                    <span x-show="form.repeat !== 'after_completion'" x-text="{ daily: $t('day(s)'), weekly: $t('week(s)'), monthly: $t('month(s)'), yearly: $t('year(s)') }[form.repeat]"></span>
                    <select x-show="form.repeat === 'after_completion'" x-model="form.unit" class="field" :aria-label="$t('Unit')">
                        <option value="daily" x-text="$t('day(s)')"></option>
                        <option value="weekly" x-text="$t('week(s)')"></option>
                        <option value="monthly" x-text="$t('month(s)')"></option>
                    </select>
                    <span x-show="form.repeat === 'after_completion'" class="text-sm text-stone-500" x-text="$t('after the last time')"></span>
                </div>

                <div x-show="form.repeat === 'weekly'" class="flex flex-wrap gap-1.5" role="group" :aria-label="$t('Weekdays')">
                    <template x-for="day in weekdays" :key="day">
                        <button type="button" class="grid h-9 min-w-11 place-items-center rounded-full border px-2 text-sm"
                            :class="form.weekdays.includes(day) ? 'border-sky-700 bg-sky-700 text-white' : 'border-stone-300 bg-white'"
                            :aria-pressed="form.weekdays.includes(day)" @click="toggleWeekday(day)" x-text="weekdayName(day)"></button>
                    </template>
                </div>

                <div x-show="form.repeat === 'monthly'" class="space-y-2 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="radio" value="day" x-model="form.monthly" class="accent-sky-700">
                        <span x-text="$t('On day')"></span>
                        <input x-model.number="form.monthDay" type="number" min="1" max="31" class="field w-20 py-1" :placeholder="form.starts_on.slice(8)" :aria-label="$t('Day of the month')">
                    </label>
                    <label class="flex flex-wrap items-center gap-2">
                        <input type="radio" value="weekday" x-model="form.monthly" class="accent-sky-700">
                        <span x-text="$t('On the')"></span>
                        <select x-model.number="form.nth" class="field py-1" :aria-label="$t('Which')">
                            <template x-for="nth in [1, 2, 3, 4, -1]" :key="nth">
                                <option :value="nth" x-text="nthLabel(nth, $t)" :selected="Number(form.nth) === nth"></option>
                            </template>
                        </select>
                        <select x-model="form.nthDay" class="field py-1" :aria-label="$t('Weekday')">
                            <template x-for="day in weekdays" :key="day">
                                <option :value="day" x-text="weekdayName(day, 'long')" :selected="form.nthDay === day"></option>
                            </template>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" value="last" x-model="form.monthly" class="accent-sky-700">
                        <span x-text="$t('On the last day of the month')"></span>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="label" x-text="form.repeat === 'once' ? $t('Date') : form.repeat === 'on_demand' ? $t('From') : $t('First date')"></span>
                        <input x-model="form.starts_on" type="date" class="field w-full" required>
                    </label>
                    <label x-show="form.repeat !== 'on_demand'" class="block">
                        <span class="label" x-text="$t('Time to do it')"></span>
                        <select x-model.number="form.window_days" class="field w-full">
                            <template x-for="days in [0, 1, 2, 3, 6, 13, 27]" :key="days">
                                <option :value="days" :selected="Number(form.window_days) === days"
                                    x-text="days === 0 ? $t('On the day') : days === 6 ? $t('1 week') : days === 13 ? $t('2 weeks') : days === 27 ? $t('4 weeks') : $t(':days more days', { days })"></option>
                            </template>
                        </select>
                    </label>
                </div>
                <label x-show="['daily', 'weekly', 'monthly', 'yearly', 'after_completion'].includes(form.repeat)" class="block">
                    <span class="label" x-text="$t('Ends on (optional)')"></span>
                    <input x-model="form.ends_on" type="date" class="field w-full" :min="form.starts_on">
                </label>

                <p class="rounded bg-stone-100 px-3 py-2 text-sm text-stone-700">
                    <span class="font-medium" x-text="schedule(formSchedule)"></span>
                    <template x-if="formPreview.length">
                        <span class="block text-stone-500" x-text="$t('Next: :dates', { dates: formPreview.map((day) => date(day)).join(', ') })"></span>
                    </template>
                </p>
            </fieldset>

            {{-- Reminders --}}
            <fieldset x-show="form.repeat !== 'on_demand'">
                <legend class="label" x-text="$t('Remind me')"></legend>
                <div class="flex flex-wrap gap-1.5">
                    {{-- Days before the (last) due day; with a window its first day replaces the chip of the same day. --}}
                    <template x-for="option in [
                        ...[{ days: 1, label: $t('1 day before') }, { days: 2, label: $t('2 days before') }, { days: 7, label: $t('1 week before') }]
                            .filter((each) => each.days !== Number(form.window_days)),
                        ...(Number(form.window_days) > 0
                            ? [{ days: Number(form.window_days), label: $t('On the first due day') }, { days: 0, label: $t('On the last due day') }]
                            : [{ days: 0, label: $t('On the due date') }]),
                    ]" :key="option.days">
                        <button type="button" class="filter-chip" :class="form.reminders.includes(option.days) && 'filter-chip-active'"
                            :aria-pressed="form.reminders.includes(option.days)" @click="toggleReminder(option.days)" x-text="option.label"></button>
                    </template>
                </div>
                <p class="mt-1 text-xs text-stone-500" x-text="$t('Sent to every member who wants reminders (settings), at their reminder time.')"></p>
            </fieldset>
            <label x-show="form.repeat === 'on_demand'" class="block">
                <span class="label" x-text="$t('Remind when not done for (days, optional)')"></span>
                <input x-model="form.remind_after_days" type="number" min="1" max="365" inputmode="numeric" class="field w-28">
            </label>

            {{-- Steps --}}
            <div>
                <span class="label" x-text="$t('Steps (optional)')"></span>
                <ul x-show="form.checklist.length" class="mb-2 divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <template x-for="step in form.checklist" :key="step.id">
                        <li class="flex items-center gap-2 px-3 py-2">
                            <input x-model="step.text" class="min-w-0 flex-1 bg-transparent" maxlength="255" :aria-label="$t('Step')">
                            <button type="button" class="text-stone-500" @click="removeStep(step.id)" :aria-label="$t('Remove')">✕</button>
                        </li>
                    </template>
                </ul>
                <div class="flex gap-2">
                    <input x-model="form.newStep" class="field min-w-0 flex-1" maxlength="255" :placeholder="$t('Add a step, e.g. Clean the mirror')" @keydown.enter.prevent="addStep()">
                    <button type="button" class="button-secondary" @click="addStep()" x-text="$t('Add')"></button>
                </div>
            </div>

            <label class="block">
                <span class="label" x-text="$t('Notes')"></span>
                <textarea x-model="form.notes" rows="3" class="field block w-full" maxlength="5000"></textarea>
            </label>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <button type="button" class="button-secondary" @click="goBack()" x-text="$t('Cancel')"></button>
                <button class="button" x-text="$t('Save')"></button>
            </div>
        </form>
    </template>
    <p x-show="!form && route.view === 'new' && !lists.length" class="py-8 text-center text-stone-500" x-text="$t('Create a list first.')"></p>
</section>
