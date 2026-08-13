<script>
/**
 * A native checkbox. Statamic's own Checkbox blocks the main thread for
 * seconds once a list renders hundreds of them, and it renders its value as
 * text when no label is given.
 */
export default {
    props: {
        modelValue: Boolean,
        indeterminate: Boolean,
        disabled: Boolean,
        label: String,
        showLabel: Boolean,
    },

    emits: ['update:modelValue'],

    watch: {
        /** `indeterminate` is a DOM property with no attribute, so it cannot be bound. */
        indeterminate: {
            immediate: true,
            handler(value) {
                this.$nextTick(() => {
                    if (this.$refs.input) this.$refs.input.indeterminate = value
                })
            },
        },
    },
}
</script>

<template>
    <label class="inline-flex items-center gap-2" :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer'">
        <input
            ref="input"
            type="checkbox"
            class="size-4 shrink-0 accent-primary"
            :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer'"
            :checked="modelValue"
            :disabled="disabled"
            :aria-label="label"
            @change="$emit('update:modelValue', $event.target.checked)"
        />
        <span v-if="showLabel" class="text-sm" v-text="label" />
    </label>
</template>
