<script>
import { Head } from '@statamic/cms/inertia'
import {
    Alert,
    Button,
    Card,
    Header,
    Pagination,
    Panel,
    PanelFooter,
    Text,
} from '@statamic/cms/ui'
import AboutFooter from '../components/AboutFooter.vue'
import AssetFilters, { ANY_SITE } from '../components/AssetFilters.vue'
import AssetRow from '../components/AssetRow.vue'
import AssetTableHead from '../components/AssetTableHead.vue'
import CompressAll from '../components/CompressAll.vue'
import CompressionCard from '../components/CompressionCard.vue'
import CompressionUnavailable from '../components/CompressionUnavailable.vue'
import DeletionTotals from '../components/DeletionTotals.vue'
import DownloadPdf from '../components/DownloadPdf.vue'
import IndexAlerts from '../components/IndexAlerts.vue'
import ListSkeleton from '../components/ListSkeleton.vue'
import MarkedText from '../components/MarkedText.vue'
import PageTabs from '../components/PageTabs.vue'
import RunButton from '../components/RunButton.vue'
import RunFailure from '../components/RunFailure.vue'
import DeleteConfirmation from '../components/DeleteConfirmation.vue'
import { readQuery, writeQuery } from '../support/filterQuery.js'
import { escapeHtml, formatBytes, formatDate, mark, parts } from '../support/formatting.js'
import polling from '../support/polling.js'
import runs from '../support/runs.js'

export default {
    components: {
        AboutFooter,
        Alert,
        AssetFilters,
        AssetRow,
        AssetTableHead,
        Button,
        Card,
        CompressAll,
        CompressionCard,
        CompressionUnavailable,
        DeleteConfirmation,
        DeletionTotals,
        DownloadPdf,
        Head,
        Header,
        IndexAlerts,
        ListSkeleton,
        MarkedText,
        PageTabs,
        Pagination,
        RunButton,
        RunFailure,
        Panel,
        PanelFooter,
        Text,
    },

    mixins: [polling, runs],

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
        stopUrl: String,
        statusUrl: String,
        destroyUrl: String,
        destroyUnusedUrl: String,
        containers: Array,
        sites: Array,
        compressionEnabled: Boolean,
        canCompress: Boolean,
        analyzeUrl: String,
        analyzeStopUrl: String,
        compressionStatusUrl: String,
        compressUrl: String,
        compressAllUrl: String,
        compressBatchUrl: String,
        keepOriginalsDays: Number,
        logUrl: String,
        exportUrl: String,
        exportThumbnailsUrl: String,
        exportMakeThumbnailsUrl: String,
    },

    data() {
        // A shared link opens with its filters set; see support/filterQuery.js.
        const state = readQuery(this.urlDefaults(), {
            usage: this.view === 'usage',
            compression: this.view === 'compression',
            containers: this.containers.map(container => container.handle),
            sites: this.multisite && this.view === 'usage' ? this.sites.map(site => site.handle) : [],
            sorts: ['path', 'size', 'resolution', 'dpi', 'last_modified', 'usage', ...(this.compressionEnabled ? ['savings'] : [])],
        })

        return {
            assets: [],
            meta: null,
            index: { exists: false, stale: true, aged: false, auto_update: true, building: false, built_at: null, items_scanned: 0 },
            filters: { usage: state.usage, container: state.container, site: state.site, search: state.search, compression: state.compression },
            sortColumn: state.sort,
            sortDirection: state.order,
            page: state.page,
            /** False until the first response lands, so nothing flashes an empty or stale state. */
            ready: false,
            loading: true,
            deleting: false,
            selected: [],
            expanded: [],
            confirming: null,
            confirmingAllUnused: false,
            searchTimeout: null,
            /** Numbers each load(), so a slow earlier response can't overwrite a newer one. */
            latestLoad: 0,
            /** Where the compression analysis stands; null while compression is off. */
            compression: null,
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

        /** The usage check on the overview, an analysis on the Compression page. */
        showsList() {
            if (this.view === 'compression') return !!this.compression?.available && !this.compression.never_analyzed

            return !!this.index.built_at
        },

        busy() {
            return this.loading || this.deleting || this.compressing || (this.starting === 'index' && !this.index.building)
        },

        unusedTotal() {
            return this.meta?.unused_total ?? 0
        },

        /** In the order the PDF's link to this page writes them, so the two URLs read the same. */
        urlState() {
            return {
                search: this.filters.search.trim(),
                usage: this.view === 'usage' ? this.filters.usage : undefined,
                compression: this.view === 'compression' ? this.filters.compression : undefined,
                container: this.filters.container,
                site: this.view === 'usage' ? this.filters.site : undefined,
                sort: this.sortColumn,
                order: this.sortDirection,
                page: this.page,
            }
        },

        /** On the overview a PDF needs usage data that can be believed, which the server checks as well. */
        downloadProps() {
            return {
                params: { ...this.requestParams, view: this.view },
                url: this.exportUrl,
                thumbnailsUrl: this.exportThumbnailsUrl,
                makeThumbnailsUrl: this.exportMakeThumbnailsUrl,
                disabled: this.busy || !this.meta?.total || (this.view === 'usage' && (!this.index.exists || this.index.stale)),
            }
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
    },

    methods: {
        /** How the page starts out, which is what the URL leaves out. */
        urlDefaults() {
            return {
                search: '',
                usage: 'all',
                compression: 'all',
                container: null,
                site: ANY_SITE,
                sort: this.view === 'compression' ? 'savings' : 'path',
                order: this.view === 'compression' ? 'desc' : 'asc',
                page: 1,
            }
        },

        load() {
            const current = ++this.latestLoad
            this.loading = true

            this.$axios
                .get(this.assetsUrl, { params: this.requestParams })
                .then(response => {
                    if (current !== this.latestLoad) return

                    // A shared link can point past the last page once assets are gone.
                    if (this.page > response.data.meta.last_page) {
                        this.goToPage(response.data.meta.last_page)

                        return
                    }

                    writeQuery(this.urlState, this.urlDefaults())

                    this.assets = response.data.data
                    this.meta = response.data.meta
                    this.setRun('index', response.data.meta.index)
                    this.setRun('compression', response.data.meta.compression)
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
                <RunButton
                    :running="index.building"
                    :stopping="stopping === 'index' || !!index.progress?.stopping"
                    :disabled="busy"
                    :text="starting === 'index' ? __('asset-usage::messages.index.refreshing') : __('asset-usage::messages.index.refresh')"
                    :stop-text="__('asset-usage::messages.index.stop')"
                    :stop-heading="__('asset-usage::messages.index.stop_heading')"
                    :stop-confirm="__('asset-usage::messages.index.stop_confirm')"
                    @start="startRun('index', rebuildUrl)"
                    @stop="stopRun('index', stopUrl)"
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
                <RunButton
                    v-if="canCompress"
                    :running="compression.analyzing"
                    :stopping="stopping === 'compression' || !!compression.progress?.stopping"
                    :disabled="busy || starting === 'compression'"
                    :text="starting === 'compression' ? __('asset-usage::messages.compress.analyzing') : __('asset-usage::messages.compress.analyze')"
                    :stop-text="__('asset-usage::messages.compress.analyze_stop')"
                    :stop-heading="__('asset-usage::messages.compress.analyze_stop_heading')"
                    :stop-confirm="__('asset-usage::messages.compress.analyze_stop_confirm')"
                    @start="startRun('compression', analyzeUrl)"
                    @stop="stopRun('compression', analyzeStopUrl)"
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

            <IndexAlerts v-else-if="view === 'usage'" :index="index" />

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

            <CompressionUnavailable
                v-if="view === 'compression' && compression && !compression.available"
                :requirements="compression.requirements"
            />

            <template v-else-if="view === 'compression' && compression">
                <Alert
                    v-if="compression.settings_changed && !compression.analyzing"
                    class="mb-4"
                    variant="warning"
                    :heading="__('asset-usage::messages.compress.settings_changed_heading')"
                    :text="__('asset-usage::messages.compress.settings_changed')"
                />

                <RunFailure
                    v-if="compression.failure"
                    :failure="compression.failure"
                    :heading="__('asset-usage::messages.compress.analyze_failed')"
                    command="php please asset-usage:analyze"
                />

                <CompressionCard class="mb-4" :compression="compression" :headline="compressionHeadline" :log-url="logUrl" />
            </template>

            <!-- Before the first check or analysis there is nothing to list yet, only the notice and the button above. -->
            <template v-if="showsList">
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

                <DeletionTotals v-if="view === 'usage' && meta?.deletions?.count" :deletions="meta.deletions" :log-url="logUrl" />

                <!-- Also for users who may not delete, who still get the PDF. -->
                <div v-if="view === 'usage'" class="mb-4 flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center">
                        <Button
                            v-if="deletes"
                            variant="danger"
                            :disabled="busy || !selected.length"
                            :text="deleteSelectedText"
                            @click="confirmDelete(selectedAssets)"
                        />

                        <DownloadPdf v-bind="downloadProps" />
                    </div>

                    <Button
                        v-if="deletes && unusedTotal"
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
                >
                    <DownloadPdf v-bind="downloadProps" />
                </CompressAll>

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
                            <AssetTableHead
                                :columns="columns"
                                :sort-column="sortColumn"
                                :sort-direction="sortDirection"
                                :selects="selects"
                                :selectable="selectableAssets.length > 0"
                                :all-selected="allSelected"
                                :some-selected="someSelected"
                                :compresses="compresses"
                                :deletes="deletes"
                                @sort="sortBy"
                                @toggle-all="toggleAll"
                            />

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
            </template>

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
