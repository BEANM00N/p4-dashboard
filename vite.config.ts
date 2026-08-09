import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
    {
        main: 'src/main.js',
    },
    {
        createEmptyCSSEntryPoints: true,
        config: {
            build: {
                cssCodeSplit: false, // Bundles all 21 kB of CSS into perforcedashboard-main.css
            },
            server: {
                watch: {
                    usePolling: true,
                },
            },
        },
    },
)