/**
 * The filters, the sort and the page in the URL, so a link to the overview
 * opens with them set. Only what differs from how the page starts out is
 * written, which leaves the page without filters at its plain URL. The link
 * in the PDF is built the same way (ExportController::overviewUrl()).
 */

const USAGES = ['all', 'used', 'unused']
const COMPRESSIONS = ['all', 'compressible', 'compressed']
const ORDERS = ['asc', 'desc']

/**
 * The state the URL asks for, on top of the defaults. A value this page or
 * this user doesn't know, such as a container they can't see, stays at its
 * default.
 *
 * @param {Object} defaults search, usage, compression, container, site, sort, order and page
 * @param {{ usage: boolean, compression: boolean, containers: string[], sites: string[], sorts: string[] }} allowed
 */
export function readQuery(defaults, allowed) {
    const query = new URLSearchParams(window.location.search)
    const state = { ...defaults }

    const take = (key, valid) => {
        const value = query.get(key)

        if (value !== null && valid(value)) state[key] = value
    }

    take('search', () => true)
    take('usage', value => allowed.usage && USAGES.includes(value))
    take('compression', value => allowed.compression && COMPRESSIONS.includes(value))
    take('container', value => allowed.containers.includes(value))
    take('site', value => allowed.sites.includes(value))
    take('sort', value => allowed.sorts.includes(value))
    take('order', value => ORDERS.includes(value))

    const page = Number.parseInt(query.get('page'), 10)

    if (page > 1) state.page = page

    return state
}

/**
 * Replaces the current history entry rather than adding one, so the back
 * button leaves the page instead of stepping back through every filter. The
 * entry keeps Inertia's own state, which it needs to restore the page.
 */
export function writeQuery(state, defaults) {
    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(state)) {
        if (value !== null && value !== undefined && value !== '' && value !== defaults[key]) query.set(key, value)
    }

    const search = query.toString()
    const url = `${window.location.pathname}${search ? `?${search}` : ''}${window.location.hash}`

    if (url === `${window.location.pathname}${window.location.search}${window.location.hash}`) return

    // Safari refuses more than a hundred replacements in ten seconds; the URL is a convenience, the list isn't.
    try {
        window.history.replaceState(window.history.state, '', url)
    } catch {}
}
