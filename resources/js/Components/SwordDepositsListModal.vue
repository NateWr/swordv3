<script setup>
import { computed } from 'vue';
import { onMounted } from 'vue';
import { ref } from 'vue';
const { useDate } = pkp.modules.useDate
const { useLocalize } = pkp.modules.useLocalize
const { useUrl } = pkp.modules.useUrl
const { useFetch } = pkp.modules.useFetch

const { t } = useLocalize()
const { formatShortDate } = useDate()

const props = defineProps({
  status: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  depositAction: { type: Function, required: true },
  redeposit: { type: String, required: true },
  redepositAction: { type: Function, default: null },
  close: { type: Function, required: true },
  itemsPerPage: { type: Number, required: true },
});

const isLoading = ref(true)
const publications = ref([])
const page = ref(1)
const countTotal = ref(0)

const fetchPublications = async () => {
  isLoading.value = true
  const { pageUrl } = useUrl(`swordv3/getPublications/${props.status}/${page.value}`)
  const { data, isSuccess, fetch } = useFetch(pageUrl)
  await fetch()
  if (isSuccess.value) {
    publications.value = data.value.publications
    countTotal.value = data.value.total
  }
  isLoading.value = false
}

const depositPublicaton = async (id) => {
  await props.depositAction(id)
  props.close()
}

const redepositGroup = async () => {
  await props.redepositAction()
  props.close()
}

const lastPage = computed(() => {
  return Math.ceil(countTotal.value / props.itemsPerPage)
})

const setPage = (p) => {
  page.value = p
  fetchPublications()
}

onMounted(() => {
  fetchPublications()
})
</script>

<template>
  <div class="swordv3-modal-root fixed inset-0 overflow-scroll flex justify-center items-center">
    <div class="swordv3-modal-content bg-secondary">
      <div class="swordv3-modal-close">
        <button @click="close">
          <PkpIcon icon="Cancel" class="h-5 w-5" />
          <span class="sr-only">{{ t('common.close') }}</span>
        </button>
      </div>
      <PkpTable>
        <template #label>
          {{ title }}
        </template>
        <template #description>
          {{ description }}
        </template>
        <template #top-controls>
          <div class="flex items-center gap-x-2">
            <PkpButton
              v-if="redeposit && redepositAction"
              :isDisabled="publications.length <= 0"
              @click="redepositGroup"
            >
              {{ redeposit }}
            </PkpButton>
          </div>
        </template>
        <PkpTableHeader>
          <PkpTableColumn>{{ t('common.id') }}</PkpTableColumn>
          <PkpTableColumn>{{ t('manager.distribution.publication') }}</PkpTableColumn>
          <PkpTableColumn>{{ t('common.status') }}</PkpTableColumn>
          <PkpTableColumn v-if="status !== 'ready'">{{ t('plugins.generic.swordv3.dateDeposited') }}</PkpTableColumn>
          <PkpTableColumn>{{ t('common.action') }}</PkpTableColumn>
        </PkpTableHeader>
        <PkpTableBody>
          <template #no-content>
            <div v-if="isLoading" class="flex items-center gap-2">
              <PkpSpinner />
              <span>{{ t('common.loading')}}</span>
            </div>
          </template>
          <PkpTableRow v-for="publication in publications" :key="publication.id">
            <PkpTableCell>{{ publication.id }}</PkpTableCell>
            <PkpTableCell :isRowHeader="true">
              {{
                t(
                  'plugins.generic.swordv3.titleWithVersion',
                  {
                    version: t('publication.version', {version: publication.version}),
                    title: publication.title,
                  }
                )
              }}
            </PkpTableCell>
            <PkpTableCell>
              <template v-if="status === 'ready'">
                {{ t('plugins.generic.swordv3.status.ready') }}
              </template>
              <template v-else>
                {{ publication.swordv3State.replace('http://purl.org/net/sword/3.0/state/', '') }}
              </template>
            </PkpTableCell>
            <PkpTableCell v-if="status !== 'ready'">
              {{ formatShortDate(publication.swordv3DateDeposited) }}
            </PkpTableCell>
            <PkpTableCell>
              <div class="flex items-center gap-2">
                <PkpButton
                  class="swordv3-whitespace-no-wrap"
                  @click="depositPublicaton(publication.id)"
                >
                  <template v-if="status === 'ready'">
                    {{ t('plugins.generic.swordv3.action.deposit') }}
                  </template>
                  <template v-else-if="status === 'deposited'">
                    {{ t('plugins.generic.swordv3.action.redeposit') }}
                  </template>
                  <template v-else>
                    {{ t('plugins.generic.swordv3.action.tryAgain') }}
                  </template>
                </PkpButton>
                <PkpButton
                  v-if="status !== 'ready'"
                  class="swordv3-whitespace-no-wrap"
                  element="a"
                  :href="publication.exportStatusDocumentUrl"
                >
                  {{ t('plugins.generic.swordv3.exportStatusDocument') }}
                </PkpButton>
              </div>
            </PkpTableCell>
          </PkpTableRow>
        </PkpTableBody>
      </PkpTable>
      <PkpPagination
        v-if="lastPage > 1"
        :current-page="page"
        :is-loading="isLoading"
        :last-page="lastPage"
        :show-adjacent-pages="2"
        @set-page="setPage"
      />
    </div>
  </div>
</template>

<style scoped>
.swordv3-modal-root {
  /**
   * Pointer-events property fixes a problem where
   * the content inside the modal was not clickable.
   */
  pointer-events: auto;
  z-index: 100;
}

.swordv3-modal-content {
  position: relative;
  width: 60rem;
  max-height: 80vh;
  overflow: auto;
  padding: 2rem;
}

.swordv3-modal-close {
  position: absolute;
  top: 0rem;
  right: 0.25rem;
  display: flex;
  justify-content: flex-end;
  align-items: center;
}

.swordv3-whitespace-no-wrap {
  white-space: nowrap;
}
</style>