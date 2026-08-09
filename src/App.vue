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
                            <span :class="$style.badge">{{ item.files.length }} FILES OPEN</span>
                        </div>
                        <p :class="$style.workspaceInfo">
                            <strong>Workspace:</strong> {{ item.workspace }}
                        </p>

                        <div v-if="expandedCardId === item.user" :class="$style.fileList">
                            <h4>Currently Checked Out Assets:</h4>
                            <ul>
                                <li v-for="file in item.files" :key="file.path">
                                    <span :class="[$style.actionTag, $style[`action_${file.action.toLowerCase()}`]]">
                                        {{ file.action }}
                                    </span>
                                    <code :class="$style.filePath">{{ file.path }}</code>
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
                <input v-model="searchQuery" type="text" placeholder="Search by owner, ID, or description..." :class="$style.searchInput" />

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
                        <p :class="$style.clMeta"><strong>Owner:</strong> {{ cl.owner }} • <em>{{ cl.timestamp }}</em></p>
                        <p :class="$style.description">{{ cl.description }}</p>

                        <div v-if="expandedCardId === cl.id" :class="$style.fileList">
                            <h4>Affected Files (showing {{ cl.files.length }}):</h4>
                            <ul>
                                <li v-for="file in cl.files" :key="file">
                                    <code :class="$style.filePath">{{ file }}</code>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-else :class="$style.emptyState">
                    <p>No changelists match your filter.</p>
                </div>
            </div>
        </NcAppContent>
    </NcContent>
</template>

<style module>
.content {
    display: flex;
    justify-content: flex-start;
    padding: 30px;
    width: 100%;
}

.dashboard {
    width: 100%;
    max-width: 900px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.commitBtn {
    background-color: #0082c9;
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: var(--border-radius);
    cursor: pointer;
    font-weight: bold;
    margin-top: 10px;
}

.commitBtn:hover {
    background-color: #006da8;
}

.searchInput,
.inputField {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    background-color: rgba(0, 0, 0, 0.25);
    color: var(--color-main-text);
    margin-bottom: 18px;
    box-sizing: border-box;
    font-size: 0.95em;
}

.settingsForm {
    background-color: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.12);
    padding: 25px;
    border-radius: 8px;
    margin-top: 20px;
}

.fieldGroup {
    margin-bottom: 15px;
}

.fieldGroup label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
}

.statusMsg {
    margin-top: 15px;
    font-weight: bold;
    color: #2e7d32;
}

.card {
    border: 1px solid rgba(255, 255, 255, 0.12);
    background-color: rgba(255, 255, 255, 0.03);
    padding: 20px;
    margin-top: 15px;
    border-radius: 8px;
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    border-color: #0082c9;
}

.expandedCard {
    border-color: #0082c9 !important;
    box-shadow: 0 0 12px rgba(0, 130, 201, 0.25);
}

.cardHeader {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cardHeader h3 {
    margin: 0;
    color: #0082c9;
    font-size: 1.35em;
    font-weight: 700;
}

.workspaceInfo {
    margin: 6px 0 0 0;
    font-size: 0.95em;
    color: rgba(255, 255, 255, 0.85);
}

.clMeta {
    margin: 4px 0 0 0;
    font-size: 0.9em;
    color: rgba(255, 255, 255, 0.7);
}

.description {
    margin-top: 6px;
    font-size: 0.95em;
}

.badge {
    background-color: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.75);
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75em;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.actionTag {
    display: inline-block;
    min-width: 54px;
    text-align: center;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75em;
    font-weight: 800;
    margin-right: 12px;
    text-transform: uppercase;
    color: #ffffff;
    letter-spacing: 0.5px;
}

.action_edit { background-color: #0082c9; }
.action_add { background-color: #2e7d32; }
.action_delete { background-color: #e53935; }

.filePath {
    font-family: monospace;
    font-size: 0.9em;
    color: rgba(255, 255, 255, 0.9);
}

.fileList {
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px dotted rgba(255, 255, 255, 0.15);
}

.fileList h4 {
    margin: 0 0 12px 0;
    font-size: 1.1em;
    font-weight: 700;
}

.fileList ul {
    list-style: none;
    padding-left: 0;
    margin: 0;
    max-height: 300px;
    overflow-y: auto;
}

.fileList li {
    padding: 5px 0;
    font-size: 0.9em;
    display: flex;
    align-items: center;
}

.navList {
    padding: 10px;
    list-style: none;
}

.navList li a {
    display: block;
    padding: 10px 14px;
    color: var(--color-text-maxcontrast);
    text-decoration: none;
    border-radius: var(--border-radius);
}

.navList li:hover a {
    background-color: var(--color-background-hover);
}

.activeNav a {
    background-color: var(--color-background-dark);
    font-weight: bold;
}

.emptyState {
    padding: 40px;
    text-align: center;
    color: rgba(255, 255, 255, 0.5);
}
</style>