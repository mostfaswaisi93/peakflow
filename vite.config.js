import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

/*
 | The three faces the design system declares, downloaded at BUILD time and
 | served from this origin.
 |
 | That is what `resources/css/app.css` means by self-hosted: an offline load
 | keeps its typography, and no third party learns which pages a customer's
 | finance team opens. The skeleton shipped Instrument Sans, which the design
 | system never mentions - so the stylesheet asked for IBM Plex and the build
 | fetched something else.
 |
 | IBM Plex Sans Arabic carries the UI because the primary locale is Arabic;
 | IBM Plex Sans carries the numerals, because numbers do not flip in RTL and
 | want a face designed for them.
 */
const fontFaces = [
    bunny('IBM Plex Sans Arabic', { weights: [400, 500, 600, 700] }),
    bunny('IBM Plex Sans', { weights: [400, 500, 600] }),
    bunny('IBM Plex Mono', { weights: [400, 500] }),
];

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            /*
             | The three faces the design system declares, downloaded at BUILD
             | time and served from this origin.
             |
             | That is what `resources/css/app.css` means by self-hosted: an
             | offline load keeps its typography, and no third party learns
             | which pages a customer's finance team opens. The skeleton
             | shipped Instrument Sans, which the design system never
             | mentions - so the stylesheet asked for IBM Plex and the build
             | fetched something else.
             |
             | IBM Plex Sans Arabic carries the UI because the primary locale
             | is Arabic; IBM Plex Sans carries the numerals, because numbers
             | do not flip in RTL and want a face designed for them.
             */
            /*
             | Off unless PEAKFLOW_FETCH_FONTS=1.
             |
             | The download needs fonts.bunny.net, and a build environment
             | whose network policy denies it fails the whole build rather
             | than the font step - so a CI box without that host allowed
             | could not build the application at all.
             |
             | With it off, the stack in resources/css/app.css falls back to
             | the system sans face. The layout holds, because the design
             | system never relies on a metric only IBM Plex has; what is lost
             | is the typography, and that is a visible difference rather than
             | a broken page.
             */
            fonts: process.env.PEAKFLOW_FETCH_FONTS === '1' ? fontFaces : [],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
