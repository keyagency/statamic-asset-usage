<script>
import { Head } from '@statamic/cms/inertia'
import {
    Alert,
    Button,
    Card,
    Description,
    Header,
    Heading,
    Icon,
    Pagination,
    Panel,
    PanelFooter,
    Text,
} from '@statamic/cms/ui'
import AboutFooter from '../components/AboutFooter.vue'
import AssetFilters, { ANY_SITE } from '../components/AssetFilters.vue'
import AssetRow from '../components/AssetRow.vue'
import CompressAll from '../components/CompressAll.vue'
import CompressionCard from '../components/CompressionCard.vue'
import ListSkeleton from '../components/ListSkeleton.vue'
import MarkedText from '../components/MarkedText.vue'
import PageTabs from '../components/PageTabs.vue'
import DeleteConfirmation from '../components/DeleteConfirmation.vue'
import RowCheckbox from '../components/RowCheckbox.vue'
import { escapeHtml, formatBytes, formatDate, mark, parts } from '../support/formatting.js'
import polling from '../support/polling.js'

export default {
    components: {
        AboutFooter,
        Alert,
        AssetFilters,
        AssetRow,
        Button,
        Card,
        CompressAll,
        CompressionCard,
        DeleteConfirmation,
        Description,
        Head,
        Header,
        Heading,
        Icon,
        ListSkeleton,
        MarkedText,
        PageTabs,
        Pagination,
        Panel,
        PanelFooter,
        RowCheckbox,
        Text,
    },

    mixins: [polling],

    props: {
        /** 'usage', or 'compression' for the same overview narrowed down to compressible images. */
        view: { type: String, default: 'usage' },
        usageUrl: String,
        compressionPageUrl: String,
        icon: String,
        multisite: Boolean,
        canDelete: Boolean,
        assetsUrl: String,
        rebuildUrl: String,
        statusUrl: String,
        destroyUrl: String,
        destroyUnusedUrl: String,
        containers: Array,
        sites: Array,
        compressionEnabled: Boolean,
        canCompress: Boolean,
        analyzeUrl: String,
        compressionStatusUrl: String,
        compressUrl: String,
        compressAllUrl: String,
        compressBatchUrl: String,
        keepOriginalsDays: Number,
        logUrl: String,
    },

    data() {
        return {
            assets: [],
            meta: null,
            index: { exists: false, stale: true, aged: false, auto_update: true, building: false, built_at: null, items_scanned: 0 },
            filters: { usage: 'all', container: null, site: ANY_SITE, search: '', compression: 'all' },
            sortColumn: this.view === 'compression' ? 'savings' : 'path',
            sortDirection: this.view === 'compression' ? 'desc' : 'asc',
            page: 1,
            /** False until the first response lands, so nothing flashes an empty or stale state. */
            ready: false,
            loading: true,
            rebuilding: false,
            deleting: false,
            selected: [],
            expanded: [],
            confirming: null,
            confirmingAllUnused: false,
            searchTimeout: null,
            poll: null,
            /** Numbers each load(), so a slow earlier response can't overwrite a newer one. */
            latestLoad: 0,
            /** Where the compression analysis stands; null while compression is off. */
            compression: null,
            startingAnalysis: false,
            compressionPoll: null,
            /** A "Compress all" run is going, which holds back the other buttons. */
            compressing: false,
        }
    },

    computed: {
        /** Deleting belongs to the usage overview; the compression view leaves it out. */
        deletes() {
            return this.canDelete && this.view === 'usage'
        },

        /** Every column can be sorted, both ways, by clicking its heading. */
        columns() {
            return [
                { field: 'path', label: __('asset-usage::messages.columns.name') },
                { field: 'size', label: __('asset-usage::messages.columns.size') },
                { field: 'resolution', label: __('asset-usage::messages.columns.resolution') },
                { field: 'dpi', label: __('asset-usage::messages.columns.dpi') },
                ...(this.showsSavings ? [{ field: 'savings', label: __('asset-usage::messages.columns.savings') }] : []),
                { field: 'last_modified', label: __('asset-usage::messages.columns.last_modified') },
                { field: 'usage', label: __('asset-usage::messages.columns.usage') },
            ]
        },

        /** The sentinel goes out as an empty value, which the server reads as "any site". */
        requestParams() {
            return {
                ...this.filters,
                site: this.filters.site === ANY_SITE ? '' : this.filters.site,
                sort: this.sortColumn,
                order: this.sortDirection,
                page: this.page,
                // Only the compression page narrows the list down by compression.
                compression: this.view === 'compression' ? this.filters.compression : undefined,
            }
        },

        siteTitles() {
            return this.sites.reduce((titles, site) => ({ ...titles, [site.handle]: site.title }), {})
        },

        /** The compression view selects images to compress, the same way the overview selects assets to delete. */
        compresses() {
            return this.canCompress && this.view === 'compression'
        },

        /** Whether the list has a checkbox column at all. */
        selects() {
            return this.deletes || this.compresses
        },

        /** Rows that may actually be deleted or compressed, which is what the header checkbox toggles. */
        selectableAssets() {
            return this.assets.filter(asset => this.isSelectable(asset))
        },

        allSelected() {
            return this.selectableAssets.length > 0 && this.selectableAssets.every(asset => this.isSelected(asset.id))
        },

        someSelected() {
            return !this.allSelected && this.selectableAssets.some(asset => this.isSelected(asset.id))
        },

        selectedAssets() {
            return this.assets.filter(asset => this.isSelected(asset.id))
        },

        /**
         * The button stays visible while nothing is selected, so it only carries
         * a count once there is one, because "(0)" reads as a broken counter.
         */
        deleteSelectedText() {
            const label = __('asset-usage::messages.delete.selected')

            return this.selected.length ? `${label} (${this.selected.length})` : label
        },

        /**
         * What an overdue rebuild is worth saying depends on whether anything
         * has been keeping the index current in the meantime.
         */
        agedInstructions() {
            const key = this.index.auto_update ? 'aged_instructions' : 'aged_instructions_manual'

            return __(`asset-usage::messages.index.${key}`, { time: this.index.built_at_relative })
        },

        /** Hidden when the image driver is missing: every cell would be empty. */
        showsSavings() {
            return this.compressionEnabled && this.compression?.available !== false
        },

        compressionAnalyzedAt() {
            if (!this.compression?.analyzed_at) return null

            return formatDate(this.compression.analyzed_at, { preset: 'datetime', month: 'long' })
        },

        /** The headline of the analysis card, with the numbers marked to stand out. */
        compressionHeadline() {
            const compression = this.compression

            if (compression.analyzing) return parts(__('asset-usage::messages.compress.analyzing'))
            if (compression.never_analyzed) return parts(__('asset-usage::messages.compress.never_analyzed_heading'))

            return parts(__n('asset-usage::messages.compress.summary', compression.compressible, {
                count: mark(compression.compressible),
                size: mark(formatBytes(compression.savable_bytes)),
                threshold: compression.threshold,
            }))
        },

        /**
         * Formatted like Statamic's own CP dates: in the browser's timezone and
         * the user's formatting locale.
         */
        builtAt() {
            return formatDate(this.index.built_at, { preset: 'datetime', month: 'long' })
        },

        busy() {
            return this.loading || this.rebuilding || this.deleting || this.compressing
        },

        unusedTotal() {
            return this.meta?.unused_total ?? 0
        },
    },

    watch: {
        'filters.usage': 'reload',
        'filters.compression': 'reload',
        'filters.container': 'reload',
        'filters.site': 'reload',

        'filters.search'() {
            clearTimeout(this.searchTimeout)
            this.searchTimeout = setTimeout(() => this.reload(), 300)
        },
    },

    mounted() {
        this.load()
    },

    beforeUnmount() {
        clearTimeout(this.searchTimeout)
        this.stopPolling()
        this.stopCompressionPolling()
    },

    methods: {
        load() {
            const current = ++this.latestLoad
            this.loading = true

            this.$axios
                .get(this.assetsUrl, { params: this.requestParams })
                .then(response => {
                    if (current !== this.latestLoad) return

                    this.assets = response.data.data
                    this.meta = response.data.meta
                    this.setIndex(response.data.meta.index)
                    this.setCompression(response.data.meta.compression)
                    // A reload after a rebuild, an analysis or a compression keeps what is still on the page and selectable.
                    this.selected = this.selected.filter(id => this.selectableAssets.some(asset => asset.id === id))
                })
                .catch(error => {
                    if (current === this.latestLoad) this.$toast.error(this.errorMessage(error))
                })
                .finally(() => {
                    if (current !== this.latestLoad) return

                    this.loading = false
                    this.ready = true
                })
        },

        reload() {
            this.page = 1
            this.load()
        },

        goToPage(page) {
            this.page = page
            this.load()
        },

        rebuild() {
            this.rebuilding = true

            this.$axios
                .post(this.rebuildUrl)
                .then(response => {
                    this.$toast.success(escapeHtml(response.data.message))
                    this.setIndex(response.data.index)
                    this.load()
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => (this.rebuilding = false))
        },

        /**
         * A queued rebuild finishes outside this request, so keep checking until
         * the index reports itself done. Under the sync queue it is already
         * finished by the time the response lands and this never starts.
         */
        setIndex(index) {
            this.index = index

            if (index.building) {
                this.startPolling()
            } else {
                this.stopPolling()
            }
        },

        startPolling() {
            this.startPoll('poll', this.statusUrl, data => {
                if (!data.index.building) {
                    this.stopPolling()
                    this.load()
                }

                this.index = data.index
            })
        },

        stopPolling() {
            this.stopPoll('poll')
        },

        analyze() {
            this.startingAnalysis = true

            this.$axios
                .post(this.analyzeUrl)
                .then(response => {
                    this.$toast.success(__('asset-usage::messages.compress.analyze_started'))
                    this.setCompression(response.data.compression)
                    this.load()
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => (this.startingAnalysis = false))
        },

        /** Same idea as setIndex: a queued analysis finishes outside this request. */
        setCompression(compression) {
            this.compression = compression

            if (compression?.analyzing) {
                this.startCompressionPolling()
            } else {
                this.stopCompressionPolling()
            }
        },

        startCompressionPolling() {
            this.startPoll('compressionPoll', this.compressionStatusUrl, data => {
                if (!data.compression.analyzing) {
                    this.stopCompressionPolling()
                    this.load()
                }

                this.compression = data.compression
            })
        },

        stopCompressionPolling() {
            this.stopPoll('compressionPoll')
        },

        formatBytes(bytes) {
            return formatBytes(bytes)
        },

        confirmDelete(assets) {
            if (!this.deletes || assets.length === 0) return

            this.confirming = assets
        },

        confirmDeleteAllUnused() {
            if (!this.deletes || !this.unusedTotal) return

            this.confirmingAllUnused = true
        },

        closeConfirmation() {
            this.confirming = null
            this.confirmingAllUnused = false
        },

        destroy() {
            if (this.confirmingAllUnused) {
                this.send(this.$axios.delete(this.destroyUnusedUrl, { params: this.requestParams }))

                return
            }

            if (!this.confirming) return

            this.send(this.$axios.delete(this.destroyUrl, { data: { ids: this.confirming.map(asset => asset.id) } }))
        },

        /** Both delete endpoints answer the same way: a count, per-asset errors and a message. */
        send(request) {
            this.deleting = true

            request
                .then(response => {
                    const errors = Object.values(response.data.errors ?? {})

                    if (response.data.deleted > 0) this.$toast.success(escapeHtml(response.data.message))

                    errors.forEach(error => this.$toast.error(escapeHtml(error)))

                    this.load()
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => {
                    this.deleting = false
                    this.closeConfirmation()
                })
        },

        toggleSelected(id) {
            this.selected = this.isSelected(id)
                ? this.selected.filter(selected => selected !== id)
                : [...this.selected, id]
        },

        toggleAll() {
            this.selected = this.allSelected ? [] : this.selectableAssets.map(asset => asset.id)
        },

        isSelected(id) {
            return this.selected.includes(id)
        },

        isSelectable(asset) {
            if (this.compresses) return asset.compression?.compressible === true

            return this.deletes && !asset.blocker
        },

        toggleExpanded(id) {
            this.expanded = this.expanded.includes(id)
                ? this.expanded.filter(expanded => expanded !== id)
                : [...this.expanded, id]
        },

        isExpanded(id) {
            return this.expanded.includes(id)
        },

        /**
         * Same behaviour as Statamic's own listings: a second click flips the
         * direction, and a new column starts ascending, dates descending.
         */
        sortBy(field) {
            if (this.sortColumn === field) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc'
            } else {
                this.sortColumn = field
                this.sortDirection = field === 'last_modified' ? 'desc' : 'asc'
            }

            this.reload()
        },

        sortIcon(field) {
            if (this.sortColumn !== field) return null

            return this.sortDirection === 'asc' ? 'sort-asc' : 'sort-desc'
        },

        ariaSort(field) {
            if (this.sortColumn !== field) return 'none'

            return this.sortDirection === 'asc' ? 'ascending' : 'descending'
        },

        /** Escaped, because it always ends up in a toast. */
        errorMessage(error) {
            return escapeHtml(error.response?.data?.message ?? error.message)
        },
    },
}
</script>

<template>
    <div>
        <Head :title="view === 'compression' ? __('asset-usage::messages.nav.compression') : __('asset-usage::messages.nav_title')" />

        <Header :title="__('asset-usage::messages.nav_title')" :icon="icon">
            <template v-if="view === 'usage'">
                <Text
                    v-if="ready && index.built_at"
                    size="sm"
                    variant="subtle"
                    :title="index.built_at_relative"
                    :text="__('asset-usage::messages.index.updated_at', { time: builtAt })"
                />
                <Button
                    variant="primary"
                    :disabled="busy"
                    :text="rebuilding || index.building ? __('asset-usage::messages.index.refreshing') : __('asset-usage::messages.index.refresh')"
                    @click="rebuild"
                />
            </template>

            <!-- The same place as on the overview: when it last ran, and the button that runs it. -->
            <template v-else-if="compression?.available">
                <Text
                    v-if="compressionAnalyzedAt"
                    size="sm"
                    variant="subtle"
                    :text="__('asset-usage::messages.compress.last_analyzed', { time: compressionAnalyzedAt })"
                />
                <Button
                    v-if="canCompress"
                    variant="primary"
                    :disabled="busy || startingAnalysis || compression.analyzing"
                    :text="compression.analyzing ? __('asset-usage::messages.compress.analyzing') : __('asset-usage::messages.compress.analyze')"
                    @click="analyze"
                />
            </template>
        </Header>

        <PageTabs
            :current="view"
            :usage-url="usageUrl"
            :compression-url="compressionEnabled ? compressionPageUrl : null"
            :log-url="logUrl"
        />

        <ListSkeleton v-if="!ready" />

        <template v-else>
            <Alert
                v-if="!containers.length"
                class="mb-4"
                variant="warning"
                :text="__('asset-usage::messages.no_containers')"
            />

            <Alert
                v-else-if="view === 'usage' && index.building"
                class="mb-4"
                :text="__('asset-usage::messages.index.checking')"
            />

            <Alert
                v-else-if="view === 'usage' && index.stale"
                class="mb-4"
                variant="warning"
                :heading="index.exists ? __('asset-usage::messages.index.stale') : __('asset-usage::messages.index.not_ready')"
                :text="index.exists ? __('asset-usage::messages.index.stale_instructions') : __('asset-usage::messages.index.not_ready_instructions')"
            />

            <!-- Deliberately not a warning: the data is usable, a rebuild is just overdue. -->
            <Alert
                v-else-if="view === 'usage' && index.aged"
                class="mb-4"
                :heading="__('asset-usage::messages.index.aged')"
                :text="agedInstructions"
            />

            <!--
                Everything about compression lives on its own page. The overview only keeps the
                Saving column and points there when there is something to gain. Centred on wider
                screens, where the button makes the row taller than the icon.
            -->
            <Alert
                v-if="view === 'usage' && compressionPageUrl && compression?.available && compression.compressible && !compression.analyzing"
                class="mb-4 sm:items-center!"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <MarkedText :parts="compressionHeadline" marked-class="font-semibold text-green-700 dark:text-green-400" />
                    <Button
                        class="shrink-0"
                        size="sm"
                        :href="compressionPageUrl"
                        :text="__('asset-usage::messages.compress.open_page')"
                    />
                </div>
            </Alert>

            <Alert v-if="view === 'compression' && compression && !compression.available" class="mb-4" variant="warning">
                <Heading :text="__('asset-usage::messages.compress.unavailable_heading')" />
                <Description :text="__('asset-usage::messages.compress.unavailable', { driver: compression.requirements.driver })" />
                <a
                    v-if="compression.requirements.driver_install_url"
                    class="mt-1 inline-block text-sm font-medium underline hover:no-underline"
                    :href="compression.requirements.driver_install_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    v-text="__('asset-usage::messages.compress.driver_link', { driver: compression.requirements.driver })"
                />
            </Alert>

            <template v-else-if="view === 'compression' && compression">
                <Alert
                    v-if="compression.settings_changed && !compression.analyzing"
                    class="mb-4"
                    variant="warning"
                    :heading="__('asset-usage::messages.compress.settings_changed_heading')"
                    :text="__('asset-usage::messages.compress.settings_changed')"
                />

                <CompressionCard class="mb-4" :compression="compression" :headline="compressionHeadline" :log-url="logUrl" />
            </template>

            <AssetFilters
                v-model:search="filters.search"
                v-model:usage="filters.usage"
                v-model:compression="filters.compression"
                v-model:container="filters.container"
                v-model:site="filters.site"
                :view="view"
                :containers="containers"
                :sites="sites"
                :multisite="multisite"
            />

            <!-- What was deleted so far, with the way to the log that lists it. -->
            <p
                v-if="view === 'usage' && meta?.deletions?.count"
                class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-400"
            >
                <Icon name="trash" class="size-4 shrink-0" />
                <span class="tabular-nums">
                    {{ __n('asset-usage::messages.log.deleted_count', meta.deletions.count, { count: meta.deletions.count }) }}
                    <span aria-hidden="true"> · </span>
                    <strong class="font-semibold" v-text="__('asset-usage::messages.log.freed', { size: formatBytes(meta.deletions.bytes) })" />
                </span>
                <Button v-if="logUrl" size="sm" :href="logUrl" :text="__('asset-usage::messages.log.view')" />
            </p>

            <div v-if="deletes" class="mb-4 flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                <Button
                    variant="danger"
                    :disabled="busy || !selected.length"
                    :text="deleteSelectedText"
                    @click="confirmDelete(selectedAssets)"
                />

                <Button
                    v-if="unusedTotal"
                    :disabled="busy"
                    :text="`${__('asset-usage::messages.delete.all_unused')} (${unusedTotal})`"
                    @click="confirmDeleteAllUnused"
                />
            </div>

            <CompressAll
                v-if="compresses"
                :summary="meta?.compress_all"
                :selected="selectedAssets"
                :params="requestParams"
                :all-url="compressAllUrl"
                :batch-url="compressBatchUrl"
                :keep-originals-days="keepOriginalsDays"
                :disabled="busy || compression?.analyzing"
                @start="compressing = true"
                @finish="compressing = false; load()"
            />

            <Text
                v-if="!assets.length"
                as="p"
                class="italic"
                size="sm"
                variant="subtle"
                :text="view !== 'compression'
                    ? __('asset-usage::messages.no_results')
                    : __(filters.compression === 'compressed' ? 'asset-usage::messages.compress.none_compressed' : 'asset-usage::messages.compress.none_compressible')"
            />

            <Panel v-else>
                <!-- Relative too, so absolutely positioned content such as the sr-only heading is clipped here as well. -->
                <Card
                    inset
                    class="relative overflow-x-auto overscroll-x-contain"
                    :class="{ 'pointer-events-none opacity-60': busy }"
                    :aria-disabled="busy"
                >
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-start dark:border-gray-700">
                                <th v-if="selects" scope="col" class="w-px py-2.5 ps-4">
                                    <!-- A block-level flex box, because an inline checkbox sits on the text baseline instead of the middle. -->
                                    <div class="flex items-center">
                                        <RowCheckbox
                                            v-if="selectableAssets.length"
                                            :model-value="allSelected"
                                            :indeterminate="someSelected"
                                            :label="compresses ? __('asset-usage::messages.compress.select_all') : __('asset-usage::messages.delete.select_all')"
                                            @update:model-value="toggleAll"
                                        />
                                    </div>
                                </th>
                                <th
                                    v-for="column in columns"
                                    :key="column.field"
                                    scope="col"
                                    class="px-4 py-2.5 text-start whitespace-nowrap"
                                    :aria-sort="ariaSort(column.field)"
                                >
                                    <Button
                                        :text="column.label"
                                        :icon-append="sortIcon(column.field)"
                                        size="sm"
                                        variant="ghost"
                                        class="-my-1 -ms-3 text-sm! font-medium! text-gray-900! dark:text-gray-400!"
                                        @click="sortBy(column.field)"
                                    />
                                </th>
                                <th v-if="deletes" scope="col" class="px-4 py-2.5">
                                    <span class="sr-only" v-text="__('asset-usage::messages.delete.action')" />
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <AssetRow
                                v-for="asset in assets"
                                :key="asset.id"
                                :asset="asset"
                                :selects="selects"
                                :selectable="isSelectable(asset)"
                                :selected="isSelected(asset.id)"
                                :expanded="isExpanded(asset.id)"
                                :deletes="deletes"
                                :shows-savings="showsSavings"
                                :can-compress="canCompress"
                                :compress-url="compressUrl"
                                :threshold="compression?.threshold"
                                :index-stale="index.stale"
                                :shows-container="containers.length > 1"
                                :column-count="columns.length"
                                :site-titles="siteTitles"
                                :multisite="multisite"
                                @toggle-select="toggleSelected(asset.id)"
                                @toggle-expand="toggleExpanded(asset.id)"
                                @delete="confirmDelete([asset])"
                            />
                        </tbody>
                    </table>
                </Card>

                <PanelFooter v-if="meta && meta.last_page > 1">
                    <Pagination
                        :class="{ 'pointer-events-none opacity-60': busy }"
                        :resource-meta="meta"
                        show-totals
                        show-page-links
                        :show-per-page-selector="false"
                        @page-selected="goToPage"
                    />
                </PanelFooter>
            </Panel>

            <AboutFooter />
        </template>

        <DeleteConfirmation
            :assets="confirming"
            :all-unused="confirmingAllUnused"
            :unused-total="unusedTotal"
            :busy="deleting"
            @confirm="destroy"
            @close="closeConfirmation"
        />
    </div>
</template>
