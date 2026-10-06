<script>
import { Head, router } from '@statamic/cms/inertia'
import { Badge, Button, ButtonGroup, Card, Header, Icon, Pagination, Panel, PanelFooter, Text } from '@statamic/cms/ui'
import CompressionTotals from '../components/CompressionTotals.vue'
import PageTabs from '../components/PageTabs.vue'
import { formatBytes, formatDate, formattingLocale } from '../support/formatting.js'

export default {
    components: {
        Badge,
        Button,
        ButtonGroup,
        Card,
        CompressionTotals,
        Head,
        Header,
        Icon,
        PageTabs,
        Pagination,
        Panel,
        PanelFooter,
        Text,
    },

    props: {
        icon: String,
        type: String,
        entries: Array,
        meta: Object,
        compressionTotals: Object,
        deletionTotals: Object,
        logUrl: String,
        usageUrl: String,
        compressionPageUrl: String,
    },

    computed: {
        filters() {
            return ['all', 'compressed', 'deleted'].map(value => ({
                value,
                label: __(`asset-usage::messages.log.filters.${value}`),
            }))
        },

        columns() {
            return ['date', 'file', 'by', 'action', 'details', 'status'].map(key => ({
                key,
                label: __(`asset-usage::messages.log.columns.${key}`),
            }))
        },

        hasCompressions() {
            return this.compressionTotals.count || this.compressionTotals.restored
        },
    },

    methods: {
        date(value) {
            return formatDate(value, { preset: 'datetime', month: 'long' })
        },

        dimensions(width, height) {
            return width ? `${width} × ${height}` : ''
        },

        saving(entry) {
            return `−${new Intl.NumberFormat(formattingLocale(), { maximumFractionDigits: 1 }).format(entry.savings)}%`
        },

        freed() {
            return __('asset-usage::messages.log.freed', { size: formatBytes(this.deletionTotals.bytes) })
        },

        restoredText(entry) {
            const date = this.date(entry.restored_at)

            return entry.restored_by
                ? __('asset-usage::messages.log.restored_on', { date, user: entry.restored_by.name })
                : __('asset-usage::messages.log.restored_on_unknown', { date })
        },

        filter(type) {
            router.get(this.logUrl, type === 'all' ? {} : { type }, { preserveScroll: true })
        },

        goToPage(page) {
            router.get(this.logUrl, { ...(this.type === 'all' ? {} : { type: this.type }), page }, { preserveScroll: true })
        },
    },
}
</script>

<template>
    <div>
        <Head :title="__('asset-usage::messages.log.title')" />

        <Header :title="__('asset-usage::messages.nav_title')" :icon="icon" />

        <PageTabs current="log" :usage-url="usageUrl" :compression-url="compressionPageUrl" :log-url="logUrl" />

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <p v-if="hasCompressions" class="flex gap-2">
                    <Icon name="assets" class="mt-0.5 size-4 shrink-0" />
                    <CompressionTotals :totals="compressionTotals" />
                </p>
                <p v-if="deletionTotals.count" class="flex gap-2">
                    <Icon name="trash" class="mt-0.5 size-4 shrink-0" />
                    <span class="tabular-nums">
                        {{ __n('asset-usage::messages.log.deleted_count', deletionTotals.count, { count: deletionTotals.count }) }}
                        <span aria-hidden="true"> · </span>
                        <strong class="font-semibold" v-text="freed()" />
                    </span>
                </p>
            </div>

            <ButtonGroup class="shrink-0" role="group" :aria-label="__('asset-usage::messages.log.filter_label')">
                <Button
                    v-for="option in filters"
                    :key="option.value"
                    size="sm"
                    :variant="type === option.value ? 'primary' : 'default'"
                    :aria-pressed="String(type === option.value)"
                    :text="option.label"
                    @click="filter(option.value)"
                />
            </ButtonGroup>
        </div>

        <Text
            v-if="!entries.length"
            as="p"
            class="italic"
            size="sm"
            variant="subtle"
            :text="__('asset-usage::messages.log.empty')"
        />

        <Panel v-else>
            <Card inset class="relative overflow-x-auto overscroll-x-contain">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                class="px-4 py-2.5 text-start font-medium whitespace-nowrap"
                                v-text="column.label"
                            />
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="entry in entries"
                            :key="entry.id"
                            class="border-b border-gray-200 last:border-b-0 dark:border-gray-700"
                            :class="{ 'opacity-60': entry.restored_at }"
                        >
                            <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="date(entry.at)" />

                            <td class="w-full px-4 py-2.5">
                                <div class="flex min-w-56 items-center gap-3">
                                    <img
                                        v-if="entry.thumbnail"
                                        class="size-10 shrink-0 rounded object-cover"
                                        :src="entry.thumbnail"
                                        alt=""
                                    />
                                    <a
                                        v-if="entry.edit_url"
                                        class="font-medium break-all hover:underline"
                                        :href="entry.edit_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        v-text="entry.path"
                                    />
                                    <span v-else class="break-all">
                                        <span class="font-medium" v-text="entry.path" />
                                        <span
                                            v-if="entry.type === 'compressed'"
                                            class="ms-1 text-gray-500"
                                            v-text="`(${__('asset-usage::messages.log.missing')})`"
                                        />
                                    </span>
                                </div>
                            </td>

                            <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="entry.by?.name ?? ''" />

                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <Badge
                                    :color="entry.type === 'deleted' ? 'red' : 'green'"
                                    :text="__(`asset-usage::messages.log.actions.${entry.type}`)"
                                />
                            </td>

                            <td class="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                <template v-if="entry.type === 'compressed'">
                                    <div>
                                        {{ `${entry.before} → ${entry.after}` }}
                                        <span class="text-green-700 dark:text-green-400" v-text="` (${saving(entry)})`" />
                                    </div>
                                    <div
                                        class="text-xs text-gray-500 dark:text-gray-400"
                                        v-text="`${dimensions(entry.before_width, entry.before_height)} → ${dimensions(entry.after_width, entry.after_height)}`"
                                    />
                                </template>
                                <template v-else>
                                    <div v-text="entry.size ?? ''" />
                                    <div class="text-xs text-gray-500 dark:text-gray-400" v-text="dimensions(entry.width, entry.height)" />
                                </template>
                            </td>

                            <td class="px-4 py-2.5 whitespace-nowrap">
                                <template v-if="entry.type === 'compressed'">
                                    <span v-if="entry.restored_at" class="text-gray-600 dark:text-gray-400" v-text="restoredText(entry)" />
                                </template>
                                <template v-else>
                                    <div class="text-gray-600 dark:text-gray-400" v-text="__(`asset-usage::messages.log.sources.${entry.source}`)" />
                                    <div
                                        v-if="entry.usage_count"
                                        class="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400"
                                    >
                                        <Icon name="alert-warning-exclamation-mark" class="size-3.5" />
                                        <span v-text="__n('asset-usage::messages.log.still_used', entry.usage_count, { count: entry.usage_count })" />
                                    </div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <PanelFooter v-if="meta.last_page > 1">
                <Pagination
                    :resource-meta="meta"
                    show-totals
                    show-page-links
                    :show-per-page-selector="false"
                    @page-selected="goToPage"
                />
            </PanelFooter>
        </Panel>
    </div>
</template>
