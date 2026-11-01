{{-- Emoji picker for lists and tasks: pick one of the presets or type any. `model` is the Alpine property to bind. --}}
@props(['model'])
<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" class="field grid size-[2.625rem] place-items-center p-0 text-xl" @click="open = !open" :aria-label="$t('Icon')" :aria-expanded="open"
        x-text="{{ $model }} || '＋'"></button>
    <div x-show="open" x-transition class="absolute top-12 left-0 z-20 w-72 rounded-lg border border-stone-200 bg-white p-2 shadow-lg">
        <div class="grid grid-cols-8 gap-1">
            <template x-for="icon in icons" :key="icon">
                <button type="button" class="grid size-8 place-items-center rounded text-xl hover:bg-stone-100" @click="{{ $model }} = icon; open = false" x-text="icon"></button>
            </template>
        </div>
        <div class="mt-2 flex items-center gap-2">
            <input x-model="{{ $model }}" maxlength="16" class="field w-20 py-1 text-center" :aria-label="$t('Own emoji')" :placeholder="$t('Emoji')">
            <button type="button" class="text-sm underline" @click="{{ $model }} = ''; open = false" x-text="$t('None')"></button>
        </div>
    </div>
</div>
