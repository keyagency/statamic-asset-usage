/** The page number's place and look, in points: inside the bottom margin of the PDF view. */
const MARGIN = 28
const BASELINE = 14
const FONT_SIZE = 7
const GRAY = [0.42, 0.45, 0.5]

/**
 * Joins the parts of a PDF the server made one at a time, and numbers the
 * pages across them, which no part can do: it doesn't know where it starts
 * or how many pages follow. pdf-lib is loaded only here, so the CP doesn't
 * load it on every page.
 *
 * @param {ArrayBuffer[]} parts
 * @param {(page: number, pages: number) => string} pageText
 * @returns {Promise<Uint8Array>}
 */
export async function joinPdf(parts, pageText) {
    const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib')
    const joined = await PDFDocument.create()

    for (const part of parts) {
        const document = await PDFDocument.load(part)

        if (!joined.getTitle()) joined.setTitle(document.getTitle() ?? '')

        const pages = await joined.copyPages(document, document.getPageIndices())

        pages.forEach(page => joined.addPage(page))
    }

    const font = await joined.embedFont(StandardFonts.Helvetica)
    const count = joined.getPageCount()

    joined.getPages().forEach((page, index) => {
        let text = pageText(index + 1, count)

        // The standard font has no glyph for some characters, which a translation could hold.
        try {
            font.encodeText(text)
        } catch {
            text = `${index + 1} / ${count}`
        }

        page.drawText(text, {
            x: page.getWidth() - MARGIN - font.widthOfTextAtSize(text, FONT_SIZE),
            y: BASELINE,
            size: FONT_SIZE,
            font,
            color: rgb(...GRAY),
        })
    })

    return joined.save()
}
