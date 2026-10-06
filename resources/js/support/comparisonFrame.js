/** Room above the image for the labels (pt-13) and below it (pb-4). */
const PADDING = 52 + 16

/** Small images still get a frame you can grab the line in. */
const MIN_HEIGHT = 160

/**
 * What the slider and the side-by-side view share: the image drawn at the size
 * of the result, scaled to fit or zoomed in, in a frame that never takes more
 * than 70% of the screen. A component using this returns the element its image
 * is laid out in from measuredElement().
 */
export default {
    props: {
        before: String,
        after: String,
        width: Number,
        height: Number,
        /** 'fit', or a scale such as 1 or 2. */
        zoom: { type: [String, Number], default: 'fit' },
        background: String,
        beforeLabel: String,
        afterLabel: String,
    },

    data() {
        return {
            contentWidth: 0,
            viewportHeight: window.innerHeight,
            /** The size the browser reports once an image has loaded, for when no size was passed. */
            natural: null,
            observer: null,
        }
    },

    computed: {
        /** Both images together, so a change in either starts over. */
        sources() {
            return `${this.before}|${this.after}`
        },

        imageWidth() {
            return this.width || this.natural?.width || 0
        },

        imageHeight() {
            return this.height || this.natural?.height || 0
        },

        maxHeight() {
            return Math.round(this.viewportHeight * 0.7)
        },

        /** 0 until the size is known, rather than a NaN that breaks every style it ends up in. */
        scale() {
            if (!this.imageWidth || !this.imageHeight) return 0
            if (this.zoom !== 'fit') return Number(this.zoom)

            // The content width already leaves out the padding. Never scales up, so a small image stays small.
            return Math.max(0, Math.min(this.contentWidth / this.imageWidth, (this.maxHeight - PADDING) / this.imageHeight, 1))
        },

        /** As tall as the image needs, so a wide banner doesn't sit in a mostly empty frame. */
        frameHeight() {
            return Math.max(MIN_HEIGHT, Math.min(this.maxHeight, Math.round(this.imageHeight * this.scale) + PADDING))
        },

        imageSize() {
            return {
                width: `${Math.round(this.imageWidth * this.scale)}px`,
                height: `${Math.round(this.imageHeight * this.scale)}px`,
            }
        },

        /** Only a zoomed image scrolls, so only then is the frame a stop for the keyboard. */
        scrollTabindex() {
            return this.zoom === 'fit' ? null : 0
        },
    },

    watch: {
        sources() {
            this.natural = null
        },
    },

    mounted() {
        this.observer = new ResizeObserver(([entry]) => {
            this.contentWidth = entry.contentRect.width
        })

        this.observer.observe(this.measuredElement())
        window.addEventListener('resize', this.measureViewport)
    },

    beforeUnmount() {
        this.observer?.disconnect()
        window.removeEventListener('resize', this.measureViewport)
    },

    methods: {
        measureViewport() {
            this.viewportHeight = window.innerHeight
        },

        measureImage(event) {
            this.natural ??= { width: event.target.naturalWidth, height: event.target.naturalHeight }
        },
    },
}
