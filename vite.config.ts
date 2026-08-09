import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
    {
        main: resolve(join('src', 'main.js')),
    },
    {
        createEmptyCSSEntryPoints: true,
        extractLicenseInformation: true,
        thirdPartyLicense: false,
    },
    {
        build: {
            cssCodeSplit: false, // Prevents splitting CSS into separate .chunk.css files
        },
        server: {
            watch: {
                usePolling: true,
            },
        },
    },
)