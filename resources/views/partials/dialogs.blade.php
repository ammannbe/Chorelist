{{-- List settings: name, icon, ranking (owner), muting (personal), members and invitations. --}}
<dialog x-ref="listDialog" class="dialog" @close="listDialog = null">
    <template x-if="listDialog && dialogList">
        <div class="space-y-5">
            <form @submit.prevent="saveList()" class="space-y-4">
                <h2 class="text-lg font-semibold" x-text="$t('List settings')"></h2>
                <template x-if="dialogList.is_owner !== false">
                    <div class="space-y-4">
                        <div class="flex gap-2">
                            <input x-model="listDialog.icon" class="field w-14 text-center text-xl" maxlength="16" :placeholder="'📋'" :aria-label="$t('Icon')">
                            <input x-model="listDialog.name" class="field min-w-0 flex-1" required maxlength="255" :aria-label="$t('Name')">
                        </div>
                        <label class="flex items-center gap-3">
                            <input type="checkbox" class="size-4 accent-sky-700" x-model="listDialog.ranking_enabled">
                            <span x-text="$t('Points ranking')"></span>
                        </label>
                        <label x-show="listDialog.ranking_enabled" class="block">
                            <span class="label" x-text="$t('Ranking period')"></span>
                            <select x-model="listDialog.ranking_period" class="field w-full">
                                <option value="week" x-text="$t('Week (from Monday)')"></option>
                                <option value="month" x-text="$t('Month')"></option>
                                <option value="all" x-text="$t('All time')"></option>
                            </select>
                        </label>
                    </div>
                </template>
                <p x-show="dialogList.is_owner === false" class="text-sm text-stone-600" x-text="$t('Shared by :name. Name and ranking are set by the owner.', { name: memberName(dialogList.owner_email, dialogList.id) })"></p>
                <label class="flex items-start gap-3">
                    <input type="checkbox" class="mt-1 size-4 accent-sky-700" x-model="listDialog.muted">
                    <span>
                        <span class="block" x-text="$t('Mute')"></span>
                        <span class="block text-sm text-stone-500" x-text="$t('No notifications from this list (only for you).')"></span>
                    </span>
                </label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="button-secondary" @click="$refs.listDialog.close()" x-text="$t('Cancel')"></button>
                    <button class="button" x-text="$t('Save')"></button>
                </div>
            </form>

            {{-- Members (sharing needs a connection) --}}
            <div x-show="loggedIn" class="space-y-3 border-t border-stone-200 pt-4">
                <h3 class="font-semibold" x-text="$t('Members')"></h3>
                <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                    <template x-for="member in dialogList.members" :key="member.email">
                        <li class="flex items-center justify-between gap-2 px-3 py-2">
                            <span class="min-w-0">
                                <span class="block truncate" x-text="member.name || member.email"></span>
                                <span x-show="member.name" class="block truncate text-xs text-stone-500" x-text="member.email"></span>
                            </span>
                            <span x-show="member.email === dialogList.owner_email" class="text-xs text-stone-500" x-text="$t('Owner')"></span>
                            <button type="button" x-show="dialogList.is_owner && member.email !== dialogList.owner_email" class="text-sm text-red-700 underline" @click="removeMember(member.email)" :disabled="busy" x-text="$t('Remove')"></button>
                        </li>
                    </template>
                </ul>

                <template x-if="dialogList.is_owner">
                    <div class="space-y-3">
                        <p x-show="!$store.sync.online" class="notice" x-text="$t('This needs an internet connection.')"></p>
                        <form @submit.prevent="share()" class="flex gap-2">
                            <input x-model="shareEmail" type="email" class="field min-w-0 flex-1" required autocomplete="email" :placeholder="$t('Invite by email')" :aria-label="$t('Invite by email')">
                            <button class="button" :disabled="busy" x-text="$t('Invite')"></button>
                        </form>
                        <div class="space-y-2 text-sm">
                            <button type="button" x-show="!inviteLink" class="button-secondary w-full" @click="createInviteLink()" :disabled="busy" x-text="`🔗 ${$t('Create invitation link')}`"></button>
                            <template x-if="inviteLink">
                                <div class="space-y-2">
                                    <input class="field w-full font-mono text-xs" readonly :value="inviteLink" @focus="$event.target.select()" :aria-label="$t('Invitation link')">
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" class="button" @click="shareInviteLink()" x-text="$t('Share link')"></button>
                                        <button type="button" class="button-secondary" @click="copy(inviteLink)" x-text="$t('Copy link')"></button>
                                    </div>
                                    <p class="text-xs text-stone-500" x-text="$t('Valid for 7 days. Whoever opens it while logged in joins the list.')"></p>
                                </div>
                            </template>
                            <button type="button" class="text-xs text-stone-500 underline" @click="revokeInviteLinks()" :disabled="busy" x-text="$t('Revoke all invitation links')"></button>
                        </div>
                    </div>
                </template>
                <p x-show="shareError" class="text-sm text-red-700" x-text="shareError"></p>
            </div>

            <div class="flex justify-start border-t border-stone-200 pt-4 text-sm">
                <button type="button" x-show="dialogList.is_owner !== false" class="text-red-700 underline" @click="deleteList(dialogList.id)" x-text="$t('Delete list')"></button>
                <button type="button" x-show="dialogList.is_owner === false" class="text-red-700 underline" @click="leaveList(dialogList.id)" x-text="$t('Leave list')"></button>
            </div>
        </div>
    </template>
</dialog>

{{-- Done before it started: in advance (counts for that date) or once more (extra points)? --}}
<dialog x-ref="askDialog" class="dialog" @close="askDialog = null">
    <template x-if="askDialog">
        <div class="space-y-4">
            <h2 class="text-lg font-semibold" x-text="askDialog.task.title"></h2>
            <p x-show="askDialog.state.start" x-text="$t('This task is only due on :date.', { date: date(askDialog.state.start, { weekday: 'long', day: 'numeric', month: 'long' }) })"></p>
            <p x-show="!askDialog.state.start" x-text="$t('This task has no more dates.')"></p>
            <div class="space-y-2">
                <button type="button" x-show="askDialog.state.start" class="button w-full" @click="completeInAdvance()"
                    x-text="$t('Done in advance (for :date)', { date: date(askDialog.state.start) })"></button>
                <button type="button" class="button-secondary w-full" @click="completeExtra()"
                    x-text="listById(askDialog.task.task_list_id)?.ranking_enabled ? $t('Done once more (+:points extra)', { points: askDialog.task.points }) : $t('Done once more')"></button>
                <button type="button" class="w-full py-2 text-sm underline" @click="$refs.askDialog.close()" x-text="$t('Cancel')"></button>
            </div>
        </div>
    </template>
</dialog>

{{-- Done …: by whom, on which day, note --}}
<dialog x-ref="completeDialog" class="dialog" @close="completeDialog = null">
    <template x-if="completeDialog">
        <form @submit.prevent="saveComplete()" class="space-y-4">
            <h2 class="text-lg font-semibold" x-text="$t('“:task” done', { task: completeDialog.task.title })"></h2>
            <label x-show="membersOf(completeDialog.task.task_list_id).length > 1" class="block">
                <span class="label" x-text="$t('By')"></span>
                <select x-model="completeDialog.email" class="field w-full">
                    <template x-for="member in membersOf(completeDialog.task.task_list_id)" :key="member.email">
                        <option :value="member.email" x-text="memberName(member.email, completeDialog.task.task_list_id)" :selected="member.email === completeDialog.email"></option>
                    </template>
                </select>
            </label>
            <label class="block">
                <span class="label" x-text="$t('On')"></span>
                <input type="date" x-model="completeDialog.doneOn" class="field w-full" :max="today" required>
            </label>
            <label class="block">
                <span class="label" x-text="$t('Note (optional)')"></span>
                <input x-model="completeDialog.note" class="field w-full" maxlength="255">
            </label>
            <label x-show="!completeDialog.extraOnly && completeDialog.task.mode !== 'on_demand'" class="flex items-start gap-3">
                <input type="checkbox" class="mt-1 size-4 accent-sky-700" x-model="completeDialog.extra">
                <span>
                    <span class="block" x-text="$t('Once more (extra)')"></span>
                    <span class="block text-sm text-stone-500" x-text="$t('Done in addition to the plan: the next date stays.')"></span>
                </span>
            </label>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="button-secondary" @click="$refs.completeDialog.close()" x-text="$t('Cancel')"></button>
                <button class="button" x-text="$t('Save')"></button>
            </div>
        </form>
    </template>
</dialog>

{{-- Postpone the open date --}}
<dialog x-ref="postponeDialog" class="dialog" @close="postponeDialog = null">
    <template x-if="postponeDialog">
        <form @submit.prevent="savePostpone()" class="space-y-4">
            <h2 class="text-lg font-semibold" x-text="$t('Postpone “:task”', { task: postponeDialog.task.title })"></h2>
            <label class="block" x-show="!postponeDialog.impossible">
                <span class="label" x-text="$t('Due on')"></span>
                <input type="date" x-model="postponeDialog.date" class="field w-full" :min="postponeDialog.min" :max="postponeDialog.max || null" :required="!postponeDialog.impossible">
            </label>
            <p x-show="postponeDialog.max && !postponeDialog.impossible" class="text-sm text-stone-500" x-text="$t('At the latest until the day before the next date (:date).', { date: date(addDays(postponeDialog.max, 1)) })"></p>
            <p x-show="postponeDialog.impossible" class="text-sm text-stone-500" x-text="$t('The next date (:date) starts before, so this one cannot be postponed.', { date: date(addDays(postponeDialog.max, 1)) })"></p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="button-secondary" @click="$refs.postponeDialog.close()" x-text="$t('Cancel')"></button>
                <button class="button" x-show="!postponeDialog.impossible" x-text="$t('Postpone')"></button>
            </div>
        </form>
    </template>
</dialog>
