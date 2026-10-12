<script>
import { Button, Widget } from '@statamic/cms/ui'
import { Link } from '@statamic/cms/inertia'
import { formatBytes, formatDate, formattingLocale } from '../support/formatting.js'

/** Green for what is or can be saved, amber for what asks for attention, the same colours the lists use. */
const TONES = {
    green: 'text-green-700 dark:text-green-400',
    amber: 'text-amber-700 dark:text-amber-400',
}

/**
 * The dashboard widget. It renders right away and fetches its numbers
 * afterwards, because counting them lists every asset on the site.
 */
export default {
    components: { Button, Widget, Link },

    props: {
        title: String,
        icon: String,
        summaryUrl: String,
        overviewUrl: String,
        unusedUrl: String,
        compressibleUrl: String,
        logUrl: String,
    },

    data() {
        return {
            summary: null,
            failed: false,
        }
    },

    computed: {
        tiles() {
            return [this.unusedTile, this.compressionTile, this.logTile].filter(Boolean)
        },

        /** Without usage data there is no count, only the reason, so it never reads as "nothing unused". */
        unusedTile() {
            const { index, unused, assets } = this.summary

            if (unused === null) {
                const status = index.building ? 'checking' : index.exists ? 'stale' : 'not_ready'

                return {
                    key: 'unused',
                    url: this.overviewUrl,
                    status: __(`asset-usage::messages.index.${status}`),
                    tone: index.building ? null : 'amber',
                }
            }

            return {
                key: 'unused',
                url: this.unusedUrl,
                value: this.formatNumber(unused),
                tone: unused ? 'amber' : null,
                label: __n('asset-usage::messages.widget.unused', unused),
                details: [{ text: __n('asset-usage::messages.widget.total', assets, { total: this.formatNumber(assets) }) }],
            }
        },

        compressionTile() {
            const compression = this.summary.compression

            if (!compression) return null

            const status = compression.analyzing ? 'analyzing'
                : compression.never_analyzed ? 'never_analyzed_heading'
                : compression.settings_changed ? 'settings_changed_heading'
                : null

            if (status) {
                return {
                    key: 'compression',
                    url: this.compressibleUrl,
                    status: __(`asset-usage::messages.compress.${status}`),
                    tone: compression.analyzing ? null : 'amber',
                }
            }

            return {
                key: 'compression',
                url: this.compressibleUrl,
                value: this.formatNumber(compression.compressible),
                tone: compression.compressible ? 'green' : null,
                label: __n('asset-usage::messages.widget.compressible', compression.compressible),
                details: compression.compressible
                    ? [{ text: __('asset-usage::messages.widget.savable', { size: formatBytes(compression.savable_bytes) }), tone: 'green' }]
                    : [],
            }
        },

        /** Left out until something has been compressed or deleted. */
        logTile() {
            const { compression, deletions } = this.summary.log

            if (!compression.count && !deletions.count) return null

            return {
                key: 'log',
                url: this.logUrl,
                value: formatBytes(compression.before_bytes - compression.after_bytes + deletions.bytes),
                tone: 'green',
                label: __('asset-usage::messages.widget.freed'),
                details: [
                    compression.count ? { text: __n('asset-usage::messages.log.images', compression.count, { count: compression.count }) } : null,
                    deletions.count ? { text: __n('asset-usage::messages.log.deleted_count', deletions.count, { count: deletions.count }) } : null,
                ].filter(Boolean),
            }
        },

        updatedAt() {
            return __('asset-usage::messages.index.updated_at', { time: this.summary.index.built_at_relative })
        },

        builtAt() {
            return formatDate(this.summary.index.built_at, { preset: 'datetime', month: 'long' })
        },
    },

    mounted() {
        this.$axios
            .get(this.summaryUrl)
            .then(response => (this.summary = response.data))
            .catch(() => (this.failed = true))
    },

    methods: {
        formatNumber(number) {
            return new Intl.NumberFormat(formattingLocale()).format(number)
        },

        toneClass(tone, fallback) {
            return TONES[tone] ?? fallback
        },
    },
}
</script>

<template>
    <Widget :title="title" :icon="icon" :href="overviewUrl">
        <template #actions>
            <Button size="sm" :href="overviewUrl" :text="__('asset-usage::messages.widget.view_overview')" />
        </template>

        <p v-if="failed" class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400" v-text="__('asset-usage::messages.widget.failed')" />

        <div
            v-else-if="!summary"
            class="grid animate-pulse gap-4 px-4 py-3 @xl/widget:grid-cols-3"
            role="status"
            :aria-label="__('asset-usage::messages.loading')"
        >
            <div v-for="tile in 3" :key="tile" class="flex gap-x-3 @max-xl/widget:items-center @xl/widget:flex-col @xl/widget:gap-y-2">
                <div class="w-16 shrink-0 rounded bg-gray-200 @max-xl/widget:h-6 @xl/widget:h-7 dark:bg-gray-700" />
                <div class="h-3 w-28 rounded-full bg-gray-200 dark:bg-gray-700" />
            </div>
        </div>

        <template v-else>
            <!--
                Rows with the number in front while the widget is narrow, columns once there is room.
                Each property has one class per state: Statamic's own utilities outrank an addon's, so
                a variant can't override a base class core also ships, such as `flex` or `text-lg`.
            -->
            <div class="grid gap-1 px-2 pt-2 @xl/widget:auto-cols-fr @xl/widget:grid-flow-col">
                <component
                    :is="tile.url ? 'Link' : 'div'"
                    v-for="tile in tiles"
                    :key="tile.key"
                    :href="tile.url"
                    class="flex gap-x-3 rounded-lg px-2 py-2 @max-xl/widget:items-center @xl/widget:flex-col"
                    :class="{ 'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-gray-800 dark:focus-visible:bg-gray-800': tile.url }"
                >
                    <p
                        v-if="tile.status"
                        class="text-sm font-medium"
                        :class="toneClass(tile.tone, 'text-gray-900 dark:text-white')"
                        v-text="tile.status"
                    />
                    <template v-else>
                        <p
                            class="shrink-0 font-semibold tabular-nums @max-xl/widget:min-w-24 @max-xl/widget:text-lg @xl/widget:text-2xl"
                            :class="toneClass(tile.tone, 'text-gray-900 dark:text-white')"
                            v-text="tile.value"
                        />
                        <div class="min-w-0">
                            <p class="text-sm text-gray-600 dark:text-gray-400" v-text="tile.label" />
                            <p
                                v-for="detail in tile.details"
                                :key="detail.text"
                                class="text-xs tabular-nums"
                                :class="toneClass(detail.tone, 'text-gray-500 dark:text-gray-400')"
                                v-text="detail.text"
                            />
                        </div>
                    </template>
                </component>
            </div>

            <!-- The credit sits on the right while it fits on the line, and wraps to the left when it doesn't. -->
            <div class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <!-- The time stays in the text, so it doesn't depend on a tooltip; amber once a rebuild is overdue. -->
                <p
                    v-if="summary.index.built_at"
                    :class="{ 'text-amber-700 dark:text-amber-400': summary.index.aged }"
                    :title="builtAt"
                    v-text="updatedAt"
                />
                <p>
                    {{ __('asset-usage::messages.made_by') }}
                    <a
                        class="text-blue-600 hover:underline dark:text-blue-400"
                        href="https://statamic.com/creators/key-agency"
                        target="_blank"
                        rel="noopener"
                    >Key Agency</a>
                </p>
            </div>
        </template>
    </Widget>
</template>
