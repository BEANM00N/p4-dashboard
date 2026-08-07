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
    timestamp: string
}

// --- State ---
const activeTab = ref<'pending' | 'submitted' | 'settings'>('pending')
const searchQuery = ref('')
const expandedCardId = ref<number | null>(null)
const changelists = ref<Changelist[]>([])
let pollTimer: number | null = null

// --- Settings State ---
const settings = ref({
    server: '',
    user: '',
    password: ''
})
const isSavingSettings = ref(false)
const settingsStatusMessage = ref('')

// --- API Fetch Functions ---
const fetchChangelists = async () => {
    try {
        const url = generateUrl('/apps/perforcedashboard/api/changelists')
        const response = await axios.get(url)
        changelists.value = response.data
    } catch (error) {
        console.error('Failed to fetch Perforce data from PHP API:', error)
    }
}

const fetchSettings = async () => {
    try {
        const url = generateUrl('/apps/perforcedashboard/api/settings')
        const response = await axios.get(url)
        settings.value = response.data
    } catch (error) {
        console.error('Failed to load settings:', error)
    }
}

const saveSettings = async () => {
    isSavingSettings.value = true
    settingsStatusMessage.value = ''
    try {
        const url = generateUrl('/apps/perforcedashboard/api/settings')
        const response = await axios.post(url, settings.value)
        settingsStatusMessage.value = response.data.message
    } catch (error) {
        console.error('Failed to save settings:', error)
        settingsStatusMessage.value = 'Failed to save settings. Check browser console.'
    } finally {
        isSavingSettings.value = false
    }
}

// --- Lifecycle & Polling ---
onMounted(() => {
    fetchChangelists()
    fetchSettings()
    pollTimer = window.setInterval(fetchChangelists, 5000)
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

const toggleCard = (id: number) => {
    expandedCardId.value = expandedCardId.value === id ? null : id
}
</script>

<template>
    <NcContent app-name="perforcedashboard">
        <!-- Sidebar Navigation -->
        <NcAppNavigation>
            <template #list>
                <ul :class="$style.navList">
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
            <!-- Settings Panel View -->
            <div v-if="activeTab === 'settings'" :class="$style.dashboard">
                <h2>Perforce Server Settings</h2>
                <p>Configure the Helix Core server details that Nextcloud will query.</p>

                <div :class="$style.settingsForm">
                    <div :class="$style.fieldGroup">
                        <label>Server Address (P4PORT):</label>
                        <input
                            v-model="settings.server"
                            type="text"
                            placeholder="e.g. ssl:perforce.madmoonserver.com:1666"
                            :class="$style.inputField"
                        />
                    </div>

                    <div :class="$style.fieldGroup">
                        <label>P4 Username:</label>
                        <input
                            v-model="settings.user"
                            type="text"
                            placeholder="e.g. BEANM00N"
                            :class="$style.inputField"
                        />
                    </div>

                    <div :class="$style.fieldGroup">
                        <label>P4 Password or Ticket:</label>
                        <input
                            v-model="settings.password"
                            type="password"
                            placeholder="••••••••••••"
                            :class="$style.inputField"
                        />
                    </div>

                    <button :class="$style.commitBtn" :disabled="isSavingSettings" @click="saveSettings">
                        {{ isSavingSettings ? 'Saving...' : 'Save Settings' }}
                    </button>

                    <p v-if="settingsStatusMessage" :class="$style.statusMsg">
                        {{ settingsStatusMessage }}
                    </p>
                </div>
            </div>

            <!-- Changelist List View -->
            <div v-else :class="$style.dashboard">
                <div :class="$style.header">
                    <h2>Perforce Team Activity</h2>
                </div>

                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by owner, ID, or description..."
                    :class="$style.searchInput"
                />

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
                            <h4>Affected Files ({{ cl.files.length }}):</h4>
                            <ul>
                                <li v-for="file in cl.files" :key="file">
                                    <code>{{ file }}</code>
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
    background-color: var(--color-primary-element);
    color: var(--color-primary-element-text);
    border: none;
    padding: 10px 20px;
    border-radius: var(--border-radius);
    cursor: pointer;
    font-weight: bold;
    margin-top: 10px;
}

.commitBtn:hover {
    background-color: var(--color-primary-element-hover);
}

.searchInput, .inputField {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    background-color: var(--color-main-background);
    color: var(--color-main-text);
    margin-bottom: 15px;
    box-sizing: border-box;
}

.settingsForm {
    background-color: var(--color-main-background);
    border: 1px solid var(--color-border);
    padding: 25px;
    border-radius: var(--border-radius-large);
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
    color: var(--color-success);
}

.card {
    border: 1px solid var(--color-border);
    background-color: var(--color-main-background);
    padding: 20px;
    margin-top: 15px;
    border-radius: var(--border-radius-large);
    cursor: pointer;
    transition: all 0.2s ease-in-out;
}

.card:hover {
    border-color: var(--color-primary);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.expandedCard {
    border-color: var(--color-primary);
}

.cardHeader {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cardHeader h3 {
    margin: 0;
    color: var(--color-primary);
}

.badge {
    background-color: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8em;
    text-transform: uppercase;
}

.fileList {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px dashed var(--color-border);
}

.fileList ul {
    list-style: none;
    padding-left: 0;
    margin: 5px 0 0 0;
}

.fileList li {
    padding: 4px 0;
    font-size: 0.9em;
}

.navList {
    padding: 10px;
    list-style: none;
}

.navList li a {
    display: block;
    padding: 10px;
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
    color: var(--color-text-maxcontrast);
}
</style>