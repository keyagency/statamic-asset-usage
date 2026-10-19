<script>
import { Select } from '@statamic/cms/ui'

/** Room for the clear button, which only shows while something is selected. */
const CLEAR_BUTTON_REM = 1.75

/**
 * Statamic's Select, always as wide as its longest option, so no option is
 * cut off in it or in its dropdown. The core classes on the Select win from
 * a width given to it (see AGENTS.md), so the wrapper gets the width instead:
 * the longest label, measured where the label is, plus what the trigger needs
 * around a label (its padding and chevron).
 */
export default {
    components: { Select },

    inheritAttrs: false,

    props: {
        options: { type: Array, default: () => [] },
        optionLabel: { type: String, default: 'label' },
        optionValue: { type: String, default: 'value' },
        clearable: Boolean,
    },

    data() {
        return {
            minWidth: null,
        }
    },

    computed: {
        /** The class and style size the wrapper; everything else goes to the select. */
        selectAttrs() {
            const { class: _, style: __, ...attrs } = this.$attrs

            return attrs
        },
    },

    watch: {
        options: {
            handler() {
                this.$nextTick(() => this.fit())
            },
            deep: true,
        },
    },

    mounted() {
        this.fit()

        /*
         * Measured again whenever the trigger or its label's part of it changes
         * size: the chevron is added a moment after mounting, and a font can load
         * late. It settles at once, since the same measurement gives the same width.
         */
        const trigger = this.trigger()

        if (trigger && window.ResizeObserver) {
            this.observer = new ResizeObserver(() => this.fit())
            this.observer.observe(trigger)
            ;[...trigger.children].forEach(child => this.observer.observe(child))
        }
    },

    beforeUnmount() {
        this.observer?.disconnect()
    },

    methods: {
        trigger() {
            return this.$el.querySelector('[data-ui-combobox-trigger]')
        },

        fit() {
            const trigger = this.trigger()
            const label = trigger?.querySelector('.truncate')

            if (!label || !trigger.offsetWidth) return

            // The part of the trigger the label is in takes what the padding and the chevron leave.
            const room = [...trigger.children].find(child => child.contains(label)) ?? label.parentElement
            const clear = trigger.querySelector('[data-ui-combobox-clear-button]')
            const around = trigger.getBoundingClientRect().width - room.getBoundingClientRect().width - (clear?.getBoundingClientRect().width ?? 0)
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize)

            // Measured in the label's own place, so with its font, size and spacing.
            const probe = label.cloneNode(false)
            Object.assign(probe.style, { position: 'absolute', visibility: 'hidden', width: 'max-content', maxWidth: 'none' })
            label.parentElement.appendChild(probe)

            const widest = Math.max(0, ...this.options.map(option => {
                probe.textContent = String(option[this.optionLabel] ?? '')

                return probe.getBoundingClientRect().width
            }))

            probe.remove()

            this.minWidth = Math.ceil(widest + around + (this.clearable ? CLEAR_BUTTON_REM * rem : 0))
        },
    },
}
</script>

<template>
    <!--
        A set width, not a minimum: the select would otherwise grow to whatever it holds.
        No w-full below it: core ships that class too and would win (see AGENTS.md); the
        stacked filters stretch it to the full width on small screens anyway.
    -->
    <div
        class="sm:w-(--fit-width) sm:shrink-0"
        :class="$attrs.class"
        :style="[$attrs.style, { '--fit-width': minWidth ? `${minWidth}px` : '11rem' }]"
    >
        <Select
            v-bind="selectAttrs"
            :options="options"
            :option-label="optionLabel"
            :option-value="optionValue"
            :clearable="clearable"
        />
    </div>
</template>
