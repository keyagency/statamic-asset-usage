<script>
import { Button, Card, Heading, Icon } from '@statamic/cms/ui'
import CompressionTotals from './CompressionTotals.vue'
import MarkedText from './MarkedText.vue'
import { mark, parts } from '../support/formatting.js'

/** Shown as code inside the notice that explains when images are analysed. */
const ANALYZE_COMMAND = 'php please asset-usage:analyze'

/**
 * The top of the Compression page: what the analysis found, what the addon
 * saved so far, when the numbers are made, and what this server lacks.
 */
export default {
    components: { Button, Card, CompressionTotals, Heading, Icon, MarkedText },

    props: {
        compression: Object,
        /** The same headline as the notice on the overview, so the page passes it in. */
        headline: Array,
        logUrl: String,
    },

    computed: {
        description() {
            if (this.compression.analyzing) return __('asset-usage::messages.compress.analyzing_notice')
            if (this.compression.never_analyzed) return __('asset-usage::messages.compress.never_analyzed')

            return ''
        },

        /** The command in the explanation, marked so it renders as code. */
        whenInfo() {
            return parts(__('asset-usage::messages.compress.when_info').replace(ANALYZE_COMMAND, mark(ANALYZE_COMMAND)))
        },

        /**
         * Things this server lacks, each with where to read about installing
         * it: formats the driver can't handle, and pngquant.
         */
        warnings() {
            const requirements = this.compression.requirements
            const warnings = []

            if (requirements.unsupported_formats.length) {
                warnings.push({
                    text: __('asset-usage::messages.compress.unsupported_formats', {
                        driver: requirements.driver,
                        formats: requirements.unsupported_formats.join(', ').toUpperCase(),
                    }),
                    url: requirements.driver_install_url,
                    label: __('asset-usage::messages.compress.driver_link', { driver: requirements.driver }),
                })
            }

            if (!requirements.pngquant) {
                warnings.push({
                    text: __('asset-usage::messages.compress.pngquant_missing'),
                    url: requirements.pngquant_url,
                    label: __('asset-usage::messages.compress.pngquant_link'),
                })
            }

            return warnings
        },
    },
}
</script>

<template>
    <Card>
        <div class="flex items-center gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                :class="compression.compressible && !compression.analyzing
                    ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-400'
                    : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
            >
                <Icon :name="compression.analyzing ? 'time-clock' : 'assets'" class="size-5" />
            </div>

            <div class="min-w-0">
                <!-- One element: Heading lays out its children as flex items with a gap between them. -->
                <Heading size="lg">
                    <MarkedText :parts="headline" marked-class="font-semibold text-green-700 dark:text-green-400" />
                </Heading>
                <p v-if="description" class="mt-0.5 text-sm text-gray-600 dark:text-gray-400" v-text="description" />
            </div>
        </div>

        <!-- What the addon already saved. -->
        <div
            v-if="compression.log.count || compression.log.restored"
            class="mt-4 flex flex-col gap-2 rounded-lg bg-gray-50 px-3 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800/60"
        >
            <p class="text-gray-700 dark:text-gray-300">
                <CompressionTotals :totals="compression.log" />
            </p>
            <Button v-if="logUrl" class="shrink-0" size="sm" :href="logUrl" :text="__('asset-usage::messages.log.view')" />
        </div>

        <ul class="mt-4 space-y-2 border-t border-gray-200 pt-4 text-sm dark:border-gray-700">
            <li
                v-if="compression.unanalyzed && !compression.analyzing && !compression.never_analyzed"
                class="flex gap-2 text-gray-700 dark:text-gray-300"
            >
                <Icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span v-text="__n('asset-usage::messages.compress.unanalyzed_count', compression.unanalyzed, { count: compression.unanalyzed })" />
            </li>
            <li class="flex gap-2 text-gray-600 dark:text-gray-400">
                <Icon name="info" class="mt-0.5 size-4 shrink-0" />
                <MarkedText
                    :parts="whenInfo"
                    as="code"
                    marked-class="rounded bg-gray-100 px-1 py-0.5 font-mono text-[0.9em] text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                />
            </li>
            <li v-for="warning in warnings" :key="warning.text" class="flex gap-2 text-amber-700 dark:text-amber-400">
                <Icon name="alert-warning-exclamation-mark" class="mt-0.5 size-4 shrink-0" />
                <span>
                    {{ warning.text }}
                    <a
                        v-if="warning.url"
                        class="mt-0.5 block w-fit font-medium underline hover:no-underline"
                        :href="warning.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        v-text="warning.label"
                    />
                </span>
            </li>
        </ul>
    </Card>
</template>
