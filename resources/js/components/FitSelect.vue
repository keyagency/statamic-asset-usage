<script>
import { Select } from '@statamic/cms/ui'

const CLEAR_BUTTON_REM = 1.75

/** Core classes on the Select win from a width given to it (see AGENTS.md), so the wrapper gets the width instead. */
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

            const room = [...trigger.children].find(child => child.contains(label)) ?? label.parentElement
            const clear = trigger.querySelector('[data-ui-combobox-clear-button]')
            const around = trigger.getBoundingClientRect().width - room.getBoundingClientRect().width - (clear?.getBoundingClientRect().width ?? 0)
            const rem = parseFloat(getComputedStyle(document.documentElement).fontSize)

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
