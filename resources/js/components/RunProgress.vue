<script>
import { formattingLocale } from '../support/formatting.js'

/** How far a run is: a bar and a line of text, or only the text while the total isn't known. */
export default {
    props: {
        /** done, total (null while unknown) and whether that total is a guess. */
        progress: Object,
        /** The line under the bar, with the numbers in it. */
        text: String,
    },

    computed: {
        /** A guessed total can be passed, so it holds at 99% until the run is done. */
        percent() {
            const { done, total, estimated } = this.progress

            if (!total) return null

            return Math.min(Math.floor((done / total) * 100), estimated ? 99 : 100)
        },

        percentText() {
            return new Intl.NumberFormat(formattingLocale(), { style: 'percent' }).format(this.percent / 100)
        },
    },
}
</script>

<template>
    <div class="mt-3">
        <div
            v-if="percent !== null"
            class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
            role="progressbar"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuetext="text"
        >
            <div class="h-full rounded-full bg-green-600 transition-[width] dark:bg-green-500" :style="{ width: `${percent}%` }" />
        </div>
        <p class="mt-1.5 text-sm text-gray-600 tabular-nums dark:text-gray-400">
            <template v-if="percent !== null">
                <span class="font-medium text-gray-800 dark:text-gray-200" v-text="percentText" />
                <span aria-hidden="true"> · </span>
            </template>
            {{ text }}
        </p>
    </div>
</template>
