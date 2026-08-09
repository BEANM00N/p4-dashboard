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

const saveSettings = async () => {
    isSavingSettings.value = true
    settingsStatusMessage.value = ''
    try {
        const response = await axios.post(generateUrl('/apps/perforcedashboard/api/settings'), settings.value)
        settingsStatusMessage.value = response.data.message || 'Settings saved successfully!'
        fetchAllData()
    } catch (error) {
        console.error('Failed to save settings:', error)
        settingsStatusMessage.value = 'Failed to save settings.'
    } finally {
        isSavingSettings.value = false
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

    if (lower.endsWith('.umap')) return 'map'
    if (lower.endsWith('.cpp') || lower.endsWith('.h') || lower.endsWith('.cs')) return 'code'

    if (fileName.startsWith('m_') || fileName.startsWith('mi_') || fileName.startsWith('m3d_') || lower.includes('/materials/')) return 'material'
    if (fileName.startsWith('t_') || fileName.startsWith('tx_') || lower.includes('/textures/')) return 'texture'
    if (fileName.startsWith('l_') || fileName.startsWith('map_') || lower.includes('/maps/') || lower.includes('/levels/')) return 'map'
    if (fileName.startsWith('bp_') || fileName.startsWith('wbp_') || lower.includes('/blueprints/') || lower.includes('/ui/')) return 'blueprint'
    if (fileName.startsWith('sm_') || fileName.startsWith('sk_') || lower.includes('/meshes/') || lower.includes('/environment/')) return 'mesh'
    if (fileName.startsWith('a_') || fileName.startsWith('s_') || fileName.startsWith('sfx_') || lower.includes('/audio/') || lower.includes('/sounds/')) return 'audio'

    return 'default'
}
</script>

<template>
    <NcContent app-name="perforcedashboard">
        <!-- Sidebar Navigation -->
        <NcAppNavigation>
            <template #list>
                <ul class="p4-nav-list">
                    <li :class="{ 'p4-active-nav': activeTab === 'checkouts' }">
                        <a href="#" @click.prevent="activeTab = 'checkouts'">
                            👥 Team Checkouts ({{ teamCheckouts.length }})
                        </a>
                    </li>
                    <li :class="{ 'p4-active-nav': activeTab === 'pending' }">
                        <a href="#" @click.prevent="activeTab = 'pending'">
                            Pending Changelists ({{ changelists.filter(c => c.status === 'pending').length }})
                        </a>
                    </li>
                    <li :class="{ 'p4-active-nav': activeTab === 'submitted' }">
                        <a href="#" @click.prevent="activeTab = 'submitted'">
                            Submitted History ({{ changelists.filter(c => c.status === 'submitted').length }})
                        </a>
                    </li>
                    <li :class="{ 'p4-active-nav': activeTab === 'settings' }">
                        <a href="#" @click.prevent="activeTab = 'settings'">
                            ⚙️ Server Settings
                        </a>
                    </li>
                </ul>
            </template>
        </NcAppNavigation>

        <!-- Main Dashboard View -->
        <NcAppContent class="p4-content">
            <!-- Active Team Checkouts View -->
            <div v-if="activeTab === 'checkouts'" class="p4-dashboard">
                <div class="p4-header">
                    <h2>Active Team File Checkouts</h2>
                </div>

                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by team member, workspace, or asset path..."
                    class="p4-search-input"
                />

                <div v-if="filteredCheckouts.length > 0">
                    <div
                        v-for="item in filteredCheckouts"
                        :key="item.user"
                        :class="['p4-card', { 'p4-card-expanded': expandedCardId === item.user }]"
                        @click="toggleCard(item.user)"
                    >
                        <div class="p4-card-header">
                            <h3>👤 {{ item.user }}</h3>
                            <span class="p4-badge">{{ item.files.length }} FILES OPEN</span>
                        </div>
                        <p class="p4-workspace-info">
                            <strong>Workspace:</strong> {{ item.workspace }}
                        </p>

                        <div v-if="expandedCardId === item.user" class="p4-file-list">
                            <h4>Currently Checked Out Assets:</h4>
                            <ul>
                                <li v-for="file in item.files" :key="file.path">
                                    <span :class="['p4-action-tag', `p4-action-${file.action.toLowerCase()}`]">
                                        {{ file.action }}
                                    </span>
                                    <code :class="['p4-file-path', `p4-asset-${getAssetCategory(file.path)}`]">
                                        {{ file.path }}
                                    </code>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-else class="p4-empty-state">
                    <p>No active file checkouts found on the server.</p>
                </div>
            </div>

            <!-- Settings Panel View -->
            <div v-else-if="activeTab === 'settings'" class="p4-dashboard">
                <h2>Perforce Server Settings</h2>
                <div class="p4-settings-form">
                    <div class="p4-field-group">
                        <label>Server Address (P4PORT):</label>
                        <input v-model="settings.server" type="text" class="p4-input-field" />
                    </div>
                    <div class="p4-field-group">
                        <label>P4 Username:</label>
                        <input v-model="settings.user" type="text" class="p4-input-field" />
                    </div>
                    <div class="p4-field-group">
                        <label>P4 Password or Ticket:</label>
                        <input v-model="settings.password" type="password" class="p4-input-field" />
                    </div>
                    <button class="p4-commit-btn" :disabled="isSavingSettings" @click="saveSettings">
                        {{ isSavingSettings ? 'Saving...' : 'Save Settings' }}
                    </button>
                    <p v-if="settingsStatusMessage" class="p4-status-msg">{{ settingsStatusMessage }}</p>
                </div>
            </div>

            <!-- Changelist List View -->
            <div v-else class="p4-dashboard">
                <div class="p4-header">
                    <h2>Perforce Team Activity</h2>
                </div>
                <input v-model="searchQuery" type="text" placeholder="Search by owner, ID, or description..." class="p4-search-input" />

                <div v-if="filteredChangelists.length > 0">
                    <div
                        v-for="cl in filteredChangelists"
                        :key="cl.id"
                        :class="['p4-card', { 'p4-card-expanded': expandedCardId === cl.id }]"
                        @click="toggleCard(cl.id)"
                    >
                        <!-- Top Row: Description on left, Badge on right -->
                        <div class="p4-card-header">
                            <h3 class="p4-description-title">{{ cl.description || 'Untitled Changelist' }}</h3>
                            <span class="p4-badge">{{ cl.status }}</span>
                        </div>
                        
                        <!-- Metadata Row underneath -->
                        <p class="p4-cl-meta">
                            <span class="p4-cl-number">CL #{{ cl.id }}</span> • 
                            <strong>Owner:</strong> {{ cl.owner }} • 
                            <em>{{ cl.timestamp }}</em>
                        </p>

                        <div v-if="expandedCardId === cl.id" class="p4-file-list">
                            <h4>Affected Files (showing {{ cl.files.length }}):</h4>
                            <ul>
                                <li v-for="file in cl.files" :key="file">
                                    <code :class="['p4-file-path', `p4-asset-${getAssetCategory(file)}`]">
                                        {{ file }}
                                    </code>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-else class="p4-empty-state">
                    <p>No changelists match your filter.</p>
                </div>
            </div>
        </NcAppContent>
    </NcContent>
</template>

<style>
/* Unscoped global CSS rules */
.p4-content {
    display: flex;
    justify-content: flex-start;
    padding: 30px;
    width: 100%;
}

.p4-dashboard {
    width: 100%;
    max-width: 900px;
}

.p4-header h2 {
    font-size: 1.8em;
    font-weight: 700;
    margin-bottom: 15px;
}

.p4-search-input,
.p4-input-field {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    border-radius: 8px !important;
    background-color: rgba(0, 0, 0, 0.35) !important;
    color: #ffffff !important;
    margin-bottom: 18px;
    box-sizing: border-box;
    font-size: 0.95em;
}

.p4-card {
    border: 1px solid rgba(255, 255, 255, 0.15) !important;
    background-color: rgba(255, 255, 255, 0.04) !important;
    padding: 20px !important;
    margin-top: 15px !important;
    border-radius: 8px !important;
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.p4-card:hover {
    border-color: #0082c9 !important;
}

.p4-card-expanded {
    border-color: #0082c9 !important;
    box-shadow: 0 0 12px rgba(0, 130, 201, 0.25) !important;
}

.p4-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.p4-card-header h3 {
    margin: 0;
    color: #0082c9 !important;
    font-size: 1.35em;
    font-weight: 700;
}

/* Specific styling for the Changelist Title to handle long descriptions */
.p4-description-title {
    color: #ffffff !important;
    font-size: 1.15em !important;
    padding-right: 15px;
    word-break: break-word;
}

.p4-cl-number {
    color: #0082c9;
    font-weight: 700;
}

.p4-workspace-info {
    margin: 6px 0 0 0;
    font-size: 0.95em;
    color: rgba(255, 255, 255, 0.85);
}

.p4-cl-meta {
    margin: 8px 0 0 0;
    font-size: 0.9em;
    color: rgba(255, 255, 255, 0.7);
}

.p4-badge {
    background-color: rgba(255, 255, 255, 0.12) !important;
    color: rgba(255, 255, 255, 0.85) !important;
    padding: 4px 10px !important;
    border-radius: 12px !important;
    font-size: 0.75em;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    white-space: nowrap;
}

.p4-action-tag {
    display: inline-block;
    min-width: 54px;
    text-align: center;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75em;
    font-weight: 800;
    margin-right: 12px;
    text-transform: uppercase;
    color: #ffffff !important;
    letter-spacing: 0.5px;
}

.p4-action-edit { background-color: #0082c9 !important; }
.p4-action-add { background-color: #2e7d32 !important; }
.p4-action-delete { background-color: #e53935 !important; }

/* File Path Underlines */
.p4-file-path {
    font-family: monospace;
    font-size: 0.9em;
    color: rgba(255, 255, 255, 0.9);
    border-bottom: 2.5px solid transparent; /* Default state */
    padding-bottom: 2px;
    transition: border-color 0.2s ease;
}

/* UE Category Colors */
.p4-asset-map { border-bottom-color: #ffd54f !important; }
.p4-asset-texture { border-bottom-color: #ef5350 !important; }
.p4-asset-material { border-bottom-color: #66bb6a !important; }
.p4-asset-blueprint { border-bottom-color: #42a5f5 !important; }
.p4-asset-mesh { border-bottom-color: #ab47bc !important; }
.p4-asset-audio { border-bottom-color: #ffa726 !important; }
.p4-asset-code { border-bottom-color: #8d6e63 !important; opacity: 0.9; }

.p4-file-list {
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px dotted rgba(255, 255, 255, 0.2);
}

.p4-file-list h4 {
    margin: 0 0 12px 0;
    font-size: 1.1em;
    font-weight: 700;
}

.p4-file-list ul {
    list-style: none;
    padding-left: 0;
    margin: 0;
    max-height: 350px;
    overflow-y: auto;
}

.p4-file-list li {
    padding: 6px 0;
    font-size: 0.9em;
    display: flex;
    align-items: center;
}

.p4-nav-list {
    padding: 10px;
    list-style: none;
}

.p4-nav-list li a {
    display: block;
    padding: 10px 14px;
    color: var(--color-text-maxcontrast);
    text-decoration: none;
    border-radius: var(--border-radius);
}

.p4-nav-list li:hover a {
    background-color: var(--color-background-hover);
}

.p4-active-nav a {
    background-color: var(--color-background-dark);
    font-weight: bold;
}

.p4-settings-form {
    background-color: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.12);
    padding: 25px;
    border-radius: 8px;
    margin-top: 20px;
}

.p4-field-group {
    margin-bottom: 15px;
}

.p4-field-group label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
}

.p4-commit-btn {
    background-color: #0082c9;
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    margin-top: 10px;
}

.p4-empty-state {
    padding: 40px;
    text-align: center;
    color: rgba(255, 255, 255, 0.5);
}
</style>