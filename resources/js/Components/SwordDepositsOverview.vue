<script setup>
import { computed } from "vue"
import { onMounted } from "vue"
import { ref } from "vue"
import SwordDepositsListModal from "./SwordDepositsListModal.vue"
const { useUrl } = pkp.modules.useUrl
const { useFetch } = pkp.modules.useFetch
const { useLocalize } = pkp.modules.useLocalize
const { useModal } = pkp.modules.useModal
const { useNotify } = pkp.modules.useNotify

const { t } = useLocalize()
const { openDialog, openSideModal, closeSideModal } = useModal()
const { notify } = useNotify()

const props = defineProps({
  exportCsvUrl: { type: String, required: true },
  itemsPerPage: { type: Number, required: true },
  serviceName: { type: String, required: true },
});

const isLoading = ref(false)
const isDepositing = ref(false)
const countReady = ref(0)
const countQueued = ref(0)
const countDeposited = ref(0)
const countRejected = ref(0)
const countDeleted = ref(0)
const countUnknown = ref(0)

const countTotal = computed(() => {
  return countReady.value
    + countDeposited.value
    + countRejected.value
    + countDeleted.value
    + countUnknown.value
})

const sync = async () => {
  const { pageUrl: overviewUrl } = useUrl("swordv3/overview")
  const { data, isSuccess, fetch } = useFetch(overviewUrl)
  await fetch()
  if (isSuccess.value) {
    countReady.value = data.value.ready
    countQueued.value = data.value.queued
    countDeposited.value = data.value.deposited
    countRejected.value = data.value.rejected
    countDeleted.value = data.value.deleted
    countUnknown.value = data.value.unknown
  }
}

/**
 * Continue to sync the counts until there are
 * no more queued deposit jobs waiting to be
 * completed
 */
const syncUntilQueueCleared = async () => {
  await sync()
  if (countQueued.value) {
    setTimeout(syncUntilQueueCleared, 2000)
  }
}

const notifyQueued = (count, service) => {
  notify(
    t('plugins.generic.swordv3.action.depositsQueued', {
      count,
      service,
    }),
    'success'
  )
}

const deposit = async (id = null) => {
  isDepositing.value = true
  const url = `swordv3/deposit${id ? `/${id}` : ''}`
  const { pageUrl: depositUrl } = useUrl(url)
  const { data, isSuccess, fetch } = useFetch(depositUrl, {method: 'PUT'})
  await fetch()
  if (isSuccess.value) {
    await sync()
    notifyQueued(data.value.count, props.serviceName)
    syncUntilQueueCleared()
  }
  isDepositing.value = false
}

const redepositAll = async (status = null) => {
  const { pageUrl: depositUrl } = useUrl(
    status
      ? `swordv3/redeposit/${status}`
      : `swordv3/redeposit`
  )
  const { data, isSuccess, fetch } = useFetch(depositUrl, {method: 'PUT'})
  await fetch()
  if (isSuccess.value) {
    await sync()
    notifyQueued(data.value.count, props.serviceName)
    syncUntilQueueCleared()
  }
}

const redepositRejected = async () => await redepositAll('rejected')
const redepositDeleted = async () => await redepositAll('deleted')

const openRedepositDialog = (status = null) => {
  let title = t('plugins.generic.swordv3.action.redepositAll')
  let button = t('plugins.generic.swordv3.action.redepositAll.button')
  let count = countTotal.value
  if (status === 'rejected') {
    title = t('plugins.generic.swordv3.action.redepositRejected')
    button = t('plugins.generic.swordv3.action.redepositRejected')
    count = countRejected.value
  } else if (status === 'deleted') {
    title = t('plugins.generic.swordv3.action.redepositDeleted')
    button = t('plugins.generic.swordv3.action.redepositDeleted')
    count = countDeleted.value
  } else if (status === 'unknown') {
    title = t('plugins.generic.swordv3.action.redepositUnknown')
    button = t('plugins.generic.swordv3.action.redepositUnknown')
    count = countUnknown.value
  }
  openDialog({
    title,
    message: t(
      'plugins.generic.swordv3.action.redeposit.confirm',
      {
        count,
        service: props.serviceName,
      }
    ),
    actions: [
      {
        label: button,
        isPrimary: true,
        callback: async (close) => {
          await redepositAll(status)
          close()
        }
      },
      {
        label: t('common.cancel'),
        isWarnable: true,
        callback: (close) => {
          close()
        },
      }
    ],
    modalStyle: 'basic',
  })
}

const view = (status) => {
  let title, description, redeposit = ''
  let redepositAction = null
  switch (status) {
    case 'ready':
      title = t('plugins.generic.swordv3.status.ready')
      description = t('plugins.generic.swordv3.status.ready.withCount', {count: countReady.value})
      break
    case 'deposited':
      title = t('plugins.generic.swordv3.status.deposited')
      description = t('plugins.generic.swordv3.status.deposited.withCount', {count: countDeposited.value})
      break
    case 'rejected':
      title = t('plugins.generic.swordv3.status.rejected')
      description = t('plugins.generic.swordv3.status.rejected.withCount', {count: countRejected.value})
      redeposit = t('plugins.generic.swordv3.action.redepositRejected')
      redepositAction = redepositRejected
      break
    case 'deleted':
      title = t('plugins.generic.swordv3.status.deleted')
      description = t('plugins.generic.swordv3.status.deleted.withCount', {count: countDeleted.value})
      redeposit = t('plugins.generic.swordv3.action.redepositDeleted')
      redepositAction = redepositDeleted
      break
    case 'unknown':
      title = t('plugins.generic.swordv3.status.unknown')
      description = t('plugins.generic.swordv3.status.unknown.withCount', {count: countUnknown.value})
      break
  }
  openSideModal(
    SwordDepositsListModal,
    {
      status,
      title,
      description,
      depositAction: deposit,
      itemsPerPage: props.itemsPerPage,
      redeposit,
      redepositAction,
      close: closeView,
    }
  )
}

const closeView = () => {
  closeSideModal(SwordDepositsListModal)
}

onMounted(() => {
  isLoading.value = true
  sync()
    .then(() => {
      isLoading.value = false
      if (countQueued.value) {
        setTimeout(syncUntilQueueCleared, 2000)
      }
    })
})
</script>

<template>
  <div v-if="isLoading">
    <PkpSpinner />
  </div>
  <div v-else class="flex flex-col gap-8">
    <PkpTable>
      <template #label>
        {{ t('plugins.generic.swordv3.name') }}
      </template>
      <template #description>
        {{ t('plugins.generic.swordv3.status.ready.withCount', {count: countReady}) }}
      </template>
      <template #top-controls>
        <div class="flex items-center gap-x-2">
          <PkpSpinner v-if="isDepositing || countQueued" />
          <div v-if="countQueued" class="me-2 text-sm">
            {{ t('plugins.generic.swordv3.processingQueue', {count: countQueued}) }}
          </div>
          <PkpButton
            :is-primary="true"
            :is-disabled="countReady <= 0 || isDepositing"
            @click="deposit(null)"
          >
            {{ t('plugins.generic.swordv3.action.deposit') }}
          </PkpButton>
        </div>
      </template>
      <PkpTableHeader>
        <PkpTableColumn>{{ t('common.status') }}</PkpTableColumn>
        <PkpTableColumn>{{ t('common.description') }}</PkpTableColumn>
        <PkpTableColumn>{{ t('common.count') }}</PkpTableColumn>
        <PkpTableColumn>{{ t('common.action') }}</PkpTableColumn>
      </PkpTableHeader>
      <PkpTableBody>
        <PkpTableRow>
          <PkpTableCell :isRowHeader="true"><strong>{{ t('plugins.generic.swordv3.status.ready') }}</strong></PkpTableCell>
          <PkpTableCell>{{ t('plugins.generic.swordv3.status.ready.description') }}</PkpTableCell>
          <PkpTableCell>{{ countReady }}</PkpTableCell>
          <PkpTableCell><PkpButton @click="view('ready')">{{ t('common.view') }}</PkpButton></PkpTableCell>
        </PkpTableRow>
        <PkpTableRow>
          <PkpTableCell :isRowHeader="true"><strong>{{ t('plugins.generic.swordv3.status.deposited') }}</strong></PkpTableCell>
          <PkpTableCell>{{ t('plugins.generic.swordv3.status.deposited.description') }}</PkpTableCell>
          <PkpTableCell>{{ countDeposited }}</PkpTableCell>
          <PkpTableCell><PkpButton @click="view('deposited')">{{ t('common.view') }}</PkpButton></PkpTableCell>
        </PkpTableRow>
        <PkpTableRow>
          <PkpTableCell :isRowHeader="true"><strong>{{ t('plugins.generic.swordv3.status.rejected') }}</strong></PkpTableCell>
          <PkpTableCell>{{ t('plugins.generic.swordv3.status.rejected.description') }}</PkpTableCell>
          <PkpTableCell>{{ countRejected }}</PkpTableCell>
          <PkpTableCell>
            <PkpButton
              class="shrink-0"
              @click="view('rejected')"
            >
                {{ t('common.view') }}
            </PkpButton>
          </PkpTableCell>
        </PkpTableRow>
        <PkpTableRow>
          <PkpTableCell :isRowHeader="true"><strong>{{ t('plugins.generic.swordv3.status.deleted') }}</strong></PkpTableCell>
          <PkpTableCell>{{ t('plugins.generic.swordv3.status.deleted.description') }}</PkpTableCell>
          <PkpTableCell>{{ countDeleted }}</PkpTableCell>
          <PkpTableCell>
            <PkpButton
              class="shrink-0"
              @click="view('deleted')"
            >
                {{ t('common.view') }}
            </PkpButton>
          </PkpTableCell>
        </PkpTableRow>
        <PkpTableRow>
          <PkpTableCell :isRowHeader="true"><strong>{{ t('plugins.generic.swordv3.status.unknown') }}</strong></PkpTableCell>
          <PkpTableCell>{{ t('plugins.generic.swordv3.status.unknown.description') }}</PkpTableCell>
          <PkpTableCell>{{ countUnknown }}</PkpTableCell>
          <PkpTableCell>
            <div class="flex items-center gap-2">
              <PkpButton
                class="shrink-0"
                @click="view('unknown')"
              >
                  {{ t('common.view') }}
              </PkpButton>
            </div>
          </PkpTableCell>
        </PkpTableRow>
      </PkpTableBody>
      <template #bottom-controls>
        <div class="flex items-center gap-2">
          <pkp-button
            element="a"
            :href="exportCsvUrl"
          >
            {{ t('plugins.generic.swordv3.action.exportToCsv') }}
          </pkp-button>
        </div>
      </template>
    </PkpTable>
    <PkpActionPanel class="border border-light p-8">
      <h2>{{ t('plugins.generic.swordv3.action.redepositAll') }}</h2>
      <p id="swordv3-redeposit-all-desc">{{ t('plugins.generic.swordv3.action.redepositAll.description') }}</p>
      <template #actions>
        <PkpButton
          aria-describedby="swordv3-redeposit-all-desc"
          @click="openRedepositDialog(null)"
        >
          {{ t('plugins.generic.swordv3.action.redepositAll.button') }}
        </PkpButton>
      </template>
    </PkpActionPanel>
  </div>
</template>
