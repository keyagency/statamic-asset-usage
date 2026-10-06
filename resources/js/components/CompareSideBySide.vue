<script>
import comparisonFrame from '../support/comparisonFrame.js'

/**
 * Before and after next to each other, as the alternative to the slider.
 * Both sides are drawn at the same scale, the size of the result, and when
 * zoomed in they scroll together so they keep showing the same spot.
 */
export default {
    mixins: [comparisonFrame],

    data() {
        return {
            /** The panel being scrolled to match the other, whose own scroll event is an echo. */
            mirroring: null,
            /** Per panel, for an image that didn't load. */
            failed: {},
        }
    },

    computed: {
        panels() {
            return [
                { key: 'before', url: this.before, label: this.beforeLabel },
                { key: 'after', url: this.after, label: this.afterLabel },
            ]
        },

        imageStyle() {
            return { ...this.imageSize, background: this.background }
        },
    },

    watch: {
        sources() {
            this.failed = {}
        },
    },

    methods: {
        measuredElement() {
            return this.$refs.viewport[0]
        },

        /**
         * The echo arrives before the next frame, so the flag is cleared then.
         * Cleared that way it never outlives a scroll that fired no event (a
         * position the browser rounded to where the panel already was), which
         * would swallow the next real one.
         */
        syncScroll(event) {
            const source = event.target

            if (source === this.mirroring) return

            const other = this.$refs.viewport.find(viewport => viewport !== source)

            if (!other || (other.scrollTop === source.scrollTop && other.scrollLeft === source.scrollLeft)) return

            this.mirroring = other
            other.scrollTop = source.scrollTop
            other.scrollLeft = source.scrollLeft

            requestAnimationFrame(() => (this.mirroring = null))
        },
    },
}
</script>

<template>
    <div class="grid grid-cols-2 gap-px bg-gray-200 dark:bg-gray-700" :style="{ height: `${frameHeight}px` }">
        <div v-for="panel in panels" :key="panel.key" class="relative bg-gray-100 dark:bg-gray-900">
            <span
                class="pointer-events-none absolute top-2.5 left-4 z-10 rounded bg-black/65 px-2 py-0.5 text-xs text-white"
                v-text="panel.label"
            />

            <div
                ref="viewport"
                class="absolute inset-0 grid place-items-start overflow-auto px-4 pt-13 pb-4 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-blue-500"
                :tabindex="scrollTabindex"
                :role="scrollTabindex === null ? null : 'region'"
                :aria-label="scrollTabindex === null ? null : panel.label"
                @scroll="syncScroll"
            >
                <span
                    v-if="failed[panel.key]"
                    class="m-auto px-4 text-center text-sm text-gray-500"
                    v-text="__('asset-usage::messages.compress.preview_failed')"
                />
                <img
                    v-else
                    class="m-auto block max-w-none flex-none"
                    :src="panel.url"
                    :alt="panel.label"
                    :style="imageStyle"
                    @load="measureImage"
                    @error="failed = { ...failed, [panel.key]: true }"
                />
            </div>
        </div>
    </div>
</template>
