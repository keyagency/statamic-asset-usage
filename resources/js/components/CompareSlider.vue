<script>
import comparisonFrame from '../support/comparisonFrame.js'
import { formattingLocale } from '../support/formatting.js'

/** Where each key puts the line, from where it is now. */
const KEYS = {
    ArrowLeft: position => position - 5,
    ArrowDown: position => position - 5,
    ArrowRight: position => position + 5,
    ArrowUp: position => position + 5,
    PageDown: position => position - 25,
    PageUp: position => position + 25,
    Home: () => 0,
    End: () => 100,
}

/**
 * Before and after on top of each other, with a line that reveals one or the
 * other. Both layers are drawn at the size of the result, so at 100% one
 * screen pixel is one pixel of the compressed file.
 */
export default {
    mixins: [comparisonFrame],

    props: {
        label: String,
    },

    data() {
        return {
            position: 50,
            dragging: false,
            loaded: 0,
            failed: false,
        }
    },

    computed: {
        loading() {
            return this.loaded < 2 && !this.failed
        },

        valueText() {
            return new Intl.NumberFormat(formattingLocale(), { style: 'percent' }).format(Math.round(this.position) / 100)
        },

        canvasStyle() {
            return {
                ...this.imageSize,
                background: this.background,
                visibility: this.loading ? 'hidden' : 'visible',
            }
        },
    },

    watch: {
        sources() {
            this.loaded = 0
            this.failed = false
        },
    },

    methods: {
        measuredElement() {
            return this.$refs.stage
        },

        imageLoaded(event) {
            this.measureImage(event)
            this.loaded++
        },

        setPosition(clientX) {
            const rect = this.$refs.canvas.getBoundingClientRect()

            if (!rect.width) return

            this.position = Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100))
        },

        start(event) {
            // Only the primary button drags. On a touch screen only the handle does, so a zoomed image can still be panned with a finger.
            if (event.button !== 0 || (event.pointerType === 'touch' && !event.target.closest('[data-handle]'))) return

            this.dragging = true
            event.currentTarget.setPointerCapture(event.pointerId)
            this.setPosition(event.clientX)
        },

        move(event) {
            if (this.dragging) this.setPosition(event.clientX)
        },

        stop() {
            this.dragging = false
        },

        nudge(event) {
            const to = KEYS[event.key]

            if (!to) return

            event.preventDefault()
            this.position = Math.max(0, Math.min(100, to(this.position)))
        },
    },
}
</script>

<template>
    <div class="relative bg-gray-100 dark:bg-gray-900" :style="{ height: `${frameHeight}px` }">
        <span
            class="pointer-events-none absolute top-2.5 left-4 z-10 rounded bg-black/65 px-2 py-0.5 text-xs text-white"
            v-text="beforeLabel"
        />
        <span
            class="pointer-events-none absolute top-2.5 right-4 z-10 rounded bg-black/65 px-2 py-0.5 text-xs text-white"
            v-text="afterLabel"
        />

        <!-- margin:auto centres the image when it fits, and still scrolls from the top left when it doesn't. -->
        <div
            ref="stage"
            class="absolute inset-0 grid place-items-start overflow-auto px-4 pt-13 pb-4 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-blue-500"
            :tabindex="scrollTabindex"
            :role="scrollTabindex === null ? null : 'region'"
            :aria-label="scrollTabindex === null ? null : __('asset-usage::messages.compress.zoomed_label')"
        >
            <span v-if="loading" class="m-auto text-sm text-gray-500" v-text="__('asset-usage::messages.loading')" />
            <span v-else-if="failed" class="m-auto px-4 text-center text-sm text-gray-500" v-text="__('asset-usage::messages.compress.preview_failed')" />

            <div
                ref="canvas"
                v-show="!failed"
                class="relative m-auto flex-none cursor-ew-resize select-none focus-visible:outline-2 focus-visible:outline-blue-500"
                role="slider"
                tabindex="0"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="Math.round(position)"
                :aria-valuetext="valueText"
                :aria-label="label"
                :style="canvasStyle"
                @pointerdown="start"
                @pointermove="move"
                @pointerup="stop"
                @pointercancel="stop"
                @keydown="nudge"
            >
                <img
                    class="pointer-events-none absolute inset-0 block size-full"
                    :src="before"
                    :alt="beforeLabel"
                    @load="imageLoaded"
                    @error="failed = true"
                />
                <img
                    class="pointer-events-none absolute inset-0 block size-full"
                    :src="after"
                    :alt="afterLabel"
                    :style="{ clipPath: `inset(0 0 0 ${position}%)` }"
                    @load="imageLoaded"
                    @error="failed = true"
                />

                <div
                    class="pointer-events-none absolute inset-y-0 -ms-px w-0.5 bg-white shadow-[0_0_0_1px_rgba(0,0,0,0.4)]"
                    :style="{ left: `${position}%` }"
                >
                    <!-- The one part that drags on a touch screen, so it doesn't let touches pan. -->
                    <span
                        data-handle
                        class="pointer-events-auto absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 touch-none rounded-full bg-white px-3 py-2 text-[11px] whitespace-nowrap text-gray-900 shadow"
                        aria-hidden="true"
                    >◀ ▶</span>
                </div>
            </div>
        </div>
    </div>
</template>
