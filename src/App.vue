<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

// --- Types ---
interface Changelist {
    id: number
    owner: string
    description: string
    status: 'pending' | 'submitted'
    files: string[]
    totalFiles?: number
    truncated?: boolean
    timestamp: string
}

interface UserCheckout {
    user: string
    workspace: string
    files: Array<{ path: string; action: string }>
}

// --- State ---
const activeTab = ref<'pending' | 'submitted' | 'checkouts' | 'settings'>('checkouts')
const searchQuery = ref('')
const expandedCardId = ref<number | string | null>(null)
const changelists = ref<Changelist[]>([])
const teamCheckouts = ref<UserCheckout[]>([])
let pollTimer: number | null = null

// --- Settings State ---
const settings = ref({ server: '', user: '', password: '' })
const isSavingSettings = ref(false)
const settingsStatusMessage = ref('')

// --- API Fetch Functions ---
const fetchChangelists = async () => {
    try {
        const response = await axios.get(generateUrl('/apps/perforcedashboard/api/changelists'))
        changelists.value = response.data
    } catch (error) {
        console.error('Failed to fetch changelists:', error)
    }
}

const fetchCheckouts = async () => {
    try {
        const response = await axios.get(generateUrl('/apps/perforcedashboard/api/checkouts'))
        teamCheckouts.value = response.data
    } catch (error) {
        console.error('Failed to fetch checkouts:', error)
    }
}

const fetchSettings = async () => {
    try {
        const response = await axios.get(generateUrl('/apps/perforcedashboard/api/settings'))
        settings.value = response.data
    } catch (error) {
        console.error('Failed to load settings:', error)
    }
}

const fetchAllData = () => {
    fetchChangelists()
    fetchCheckouts()
}

// --- Lifecycle & Polling ---
onMounted(() => {
    fetchAllData()
    fetchSettings()
    pollTimer = window.setInterval(fetchAllData, 3000)
})

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer)
})

// --- Computed Filters ---
const filteredChangelists = computed(() => {
    return changelists.value.filter((cl) => {
        const matchesTab = cl.status === activeTab.value
        const matchesSearch =
            cl.owner.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            cl.description.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            cl.id.toString().includes(searchQuery.value)

        return matchesTab && matchesSearch
    })
})

const filteredCheckouts = computed(() => {
    return teamCheckouts.value.filter((tc) => {
        const q = searchQuery.value.toLowerCase()
        const matchesUser = tc.user.toLowerCase().includes(q) || tc.workspace.toLowerCase().includes(q)
        const matchesFile = tc.files.some(f => f.path.toLowerCase().includes(q))
        return matchesUser || matchesFile
    })
})

const toggleCard = (id: number | string) => {
    expandedCardId.value = expandedCardId.value === id ? null : id
}

// --- Unreal Engine Asset Category Classifier ---
const getAssetCategory = (filePath: string): string => {
    if (!filePath) return 'default'
    const lower = filePath.toLowerCase()
    const fileName = filePath.split('/').pop()?.toLowerCase() || ''

    // 1. Explicit Extensions
    if (lower.endsWith('.umap')) return 'map'
    if (lower.endsWith('.cpp') || lower.endsWith('.h') || lower.endsWith('.cs')) return 'code'

    // 2. Prefixes & Folder Patterns
    if (fileName.startsWith('m_') || fileName.startsWith('mi_') || fileName.startsWith('m3d_') || lower.includes('/materials/')) {
        return 'material' // Green
    }
    if (fileName.startsWith('t_') || fileName.startsWith('tx_') || lower.includes('/textures/')) {
        return 'texture'  // Red
    }
    if (fileName.startsWith('l_') || fileName.startsWith('map_') || lower.includes('/maps/') || lower.includes('/levels/')) {
        return 'map'      // Yellow
    }
    if (fileName.startsWith('bp_') || fileName.startsWith('wbp_') || lower.includes('/blueprints/') || lower.includes('/ui/')) {
        return 'blueprint'// Blue
    }
    if (fileName.startsWith('sm_') || fileName.startsWith('sk_') || lower.includes('/meshes/') || lower.includes('/environment/')) {
        return 'mesh'     // Purple
    }
    if (fileName.startsWith('a_') || fileName.startsWith('s_') || fileName.startsWith('sfx_') || lower.includes('/audio/') || lower.includes('/sounds/')) {
        return 'audio'    // Orange
    }

    return 'default'
}

</script>

<template>
    <NcContent app-name="perforcedashboard">
        <!-- Sidebar Navigation -->
        <NcAppNavigation>
            <template #list>
                <ul :class="$style.navList">
                    <li :class="{ [$style.activeNav]: activeTab === 'checkouts' }">
                        <a href="#" @click.prevent="activeTab = 'checkouts'">
                            👥 Team Checkouts ({{ teamCheckouts.length }})
                        </a>
                    </li>
                    <li :class="{ [$style.activeNav]: activeTab === 'pending' }">
                        <a href="#" @click.prevent="activeTab = 'pending'">
                            Pending Changelists ({{ changelists.filter(c => c.status === 'pending').length }})
                        </a>
                    </li>
                    <li :class="{ [$style.activeNav]: activeTab === 'submitted' }">
                        <a href="#" @click.prevent="activeTab = 'submitted'">
                            Submitted History ({{ changelists.filter(c => c.status === 'submitted').length }})
                        </a>
                    </li>
                    <li :class="{ [$style.activeNav]: activeTab === 'settings' }">
                        <a href="#" @click.prevent="activeTab = 'settings'">
                            ⚙️ Server Settings
                        </a>
                    </li>
                </ul>
            </template>
        </NcAppNavigation>

        <!-- Main Dashboard View -->
        <NcAppContent :class="$style.content">
            <!-- Active Team Checkouts View -->
            <div v-if="activeTab === 'checkouts'" :class="$style.dashboard">
                <div :class="$style.header">
                    <h2>Active Team File Checkouts</h2>
                </div>

                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by team member, workspace, or asset path..."
                    :class="$style.searchInput"
                />

                <div v-if="filteredCheckouts.length > 0">
                    <div
                        v-for="item in filteredCheckouts"
                        :key="item.user"
                        :class="[$style.card, { [$style.expandedCard]: expandedCardId === item.user }]"
                        @click="toggleCard(item.user)"
                    >
                        <div :class="$style.cardHeader">
                            <h3>👤 {{ item.user }}</h3>
                            <span :class="$style.badge">{{ item.files.length }} files open</span>
                        </div>
                        <p><strong>Workspace:</strong> <code>{{ item.workspace }}</code></p>

                        <div v-if="expandedCardId === item.user" :class="$style.fileList">
                            <h4>Currently Checked Out Assets:</h4>
                            <ul>
                                <li v-for="file in item.files" :key="file.path">
                                    <span :class="[$style.actionTag, $style[`action_${file.action.toLowerCase()}`]]">
                                        {{ file.action }}
                                    </span>
                                    <code :class="[$style.filePath, $style[`asset_${getAssetCategory(file.path)}`]]">
                                        {{ file.path }}
                                    </code>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-else :class="$style.emptyState">
                    <p>No active file checkouts found on the server.</p>
                </div>
            </div>

            <!-- Settings Panel View -->
            <div v-else-if="activeTab === 'settings'" :class="$style.dashboard">
                <h2>Perforce Server Settings</h2>
                <div :class="$style.settingsForm">
                    <div :class="$style.fieldGroup">
                        <label>Server Address (P4PORT):</label>
                        <input v-model="settings.server" type="text" :class="$style.inputField" />
                    </div>
                    <div :class="$style.fieldGroup">
                        <label>P4 Username:</label>
                        <input v-model="settings.user" type="text" :class="$style.inputField" />
                    </div>
                    <div :class="$style.fieldGroup">
                        <label>P4 Password or Ticket:</label>
                        <input v-model="settings.password" type="password" :class="$style.inputField" />
                    </div>
                    <button :class="$style.commitBtn" :disabled="isSavingSettings" @click="saveSettings">
                        {{ isSavingSettings ? 'Saving...' : 'Save Settings' }}
                    </button>
                    <p v-if="settingsStatusMessage" :class="$style.statusMsg">{{ settingsStatusMessage }}</p>
                </div>
            </div>

            <!-- Changelist List View -->
            <div v-else :class="$style.dashboard">
                <div :class="$style.header">
                    <h2>Perforce Team Activity</h2>
                </div>
                <input v-model="searchQuery" type="text" placeholder="Search..." :class="$style.searchInput" />

                <div v-if="filteredChangelists.length > 0">
                    <div
                        v-for="cl in filteredChangelists"
                        :key="cl.id"
                        :class="[$style.card, { [$style.expandedCard]: expandedCardId === cl.id }]"
                        @click="toggleCard(cl.id)"
                    >
                        <div :class="$style.cardHeader">
                            <h3>CL #{{ cl.id }}</h3>
                            <span :class="$style.badge">{{ cl.status }}</span>
                        </div>
                        <p><strong>Owner:</strong> {{ cl.owner }} • <em>{{ cl.timestamp }}</em></p>
                        <p>{{ cl.description }}</p>

                        <div v-if="expandedCardId === cl.id" :class="$style.fileList">
                            <h4>Affected Files (showing {{ cl.files.length }}):</h4>
                            <ul>
                                <li v-for="file in cl.files" :key="file">
                                    <code :class="[$style.filePath, $style[`asset_${getAssetCategory(file)}`]]">
                                        {{ file }}
                                    </code>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </NcAppContent>
    </NcContent>
</template>

<style module>
.content { display: flex; justify-content: flex-start; padding: 30px; width: 100%; }
.dashboard { width: 100%; max-width: 900px; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.commitBtn { background-color: var(--color-primary-element); color: var(--color-primary-element-text); border: none; padding: 10px 20px; border-radius: var(--border-radius); cursor: pointer; font-weight: bold; margin-top: 10px; }
.searchInput, .inputField { width: 100%; padding: 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background-color: var(--color-main-background); color: var(--color-main-text); margin-bottom: 15px; box-sizing: border-box; }
.settingsForm { background-color: var(--color-main-background); border: 1px solid var(--color-border); padding: 25px; border-radius: var(--border-radius-large); margin-top: 20px; }
.fieldGroup { margin-bottom: 15px; }
.fieldGroup label { display: block; font-weight: bold; margin-bottom: 5px; }
.statusMsg { margin-top: 15px; font-weight: bold; color: var(--color-success); }
.card { border: 1px solid var(--color-border); background-color: var(--color-main-background); padding: 20px; margin-top: 15px; border-radius: var(--border-radius-large); cursor: pointer; transition: all 0.2s ease-in-out; }
.card:hover { border-color: var(--color-primary); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); }
.expandedCard { border-color: var(--color-primary); }
.cardHeader { display: flex; justify-content: space-between; align-items: center; }
.cardHeader h3 { margin: 0; color: var(--color-primary); }
.badge { background-color: var(--color-background-dark); color: var(--color-text-maxcontrast); padding: 4px 8px; border-radius: 12px; font-size: 0.8em; text-transform: uppercase; }

/* Action Badges */
.actionTag {
    display: inline-block;
    min-width: 52px;
    text-align: center;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75em;
    font-weight: 800;
    margin-right: 10px;
    text-transform: uppercase;
    color: #ffffff;
    letter-spacing: 0.5px;
}

.action_edit { background-color: #0082c9; }
.action_add { background-color: #2e7d32; }
.action_delete { background-color: #e53935; }

/* File Path Underlines */
.filePath {
    border-bottom: 2.5px solid var(--color-border);
    padding-bottom: 2px;
    transition: border-color 0.2s ease;
}

/* Unreal Engine Asset Category Underline Colors */
.asset_map { border-bottom-color: #ffd54f !important; }        /* Yellow for Maps / Levels */
.asset_texture { border-bottom-color: #ef5350 !important; }    /* Red for Textures */
.asset_material { border-bottom-color: #66bb6a !important; }   /* Green for Materials */
.asset_blueprint { border-bottom-color: #42a5f5 !important; }  /* Blue for Blueprints / Widgets */
.asset_mesh { border-bottom-color: #ab47bc !important; }       /* Purple for Meshes */
.asset_audio { border-bottom-color: #ffa726 !important; }      /* Orange for Audio */
.asset_code { border-bottom-color: #8d6e63 !important; opacity: 0.9; }       /* Brown for C++ / Source */

.fileList { margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--color-border); }
.fileList ul { list-style: none; padding-left: 0; margin: 5px 0 0 0; max-height: 300px; overflow-y: auto; }
.fileList li { padding: 6px 0; font-size: 0.9em; display: flex; align-items: center; }
.navList { padding: 10px; list-style: none; }
.navList li a { display: block; padding: 10px; color: var(--color-text-maxcontrast); text-decoration: none; border-radius: var(--border-radius); }
.navList li:hover a { background-color: var(--color-background-hover); }
.activeNav a { background-color: var(--color-background-dark); font-weight: bold; }
.emptyState { padding: 40px; text-align: center; color: var(--color-text-maxcontrast); }
</style>