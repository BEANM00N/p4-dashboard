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
                                    <code class="p4-file-path">{{ file.path }}</code>
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
                        <div class="p4-card-header">
                            <h3>CL #{{ cl.id }}</h3>
                            <span class="p4-badge">{{ cl.status }}</span>
                        </div>
                        <p class="p4-cl-meta"><strong>Owner:</strong> {{ cl.owner }} • <em>{{ cl.timestamp }}</em></p>
                        <p class="p4-description">{{ cl.description }}</p>

                        <div v-if="expandedCardId === cl.id" class="p4-file-list">
                            <h4>Affected Files (showing {{ cl.files.length }}):</h4>
                            <ul>
                                <li v-for="file in cl.files" :key="file">
                                    <code class="p4-file-path">{{ file }}</code>
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