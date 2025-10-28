<tab id="swordv3" label="{translate key="plugins.generic.swordv3.name"}">
  <tabs :is-side-tabs="true" :track-history="true">
    <tab id="swordv3/swordv3-deposits" label="Deposit">
      {if !$swordv3Configured}
        <div>
          You must configure a deposit service.
        </div>
      {else}
        <div class="flex flex-col gap-4">
          <table style="text-align: left;">
            <tr>
              <th scope="row">
                {translate key="plugins.generic.swordv3.status.ready"}
              </th>
              <td>
                {translate key="plugins.generic.swordv3.status.ready.description"}
              </td>
              <td>{$notDeposited}</td>
            </tr>
            <tr>
              <th scope="row">
                {translate key="plugins.generic.swordv3.status.deposited"}
              </th>
              <td>
                {translate key="plugins.generic.swordv3.status.deposited.description"}
              </td>
              <td>{$deposited}</td>
            </tr>
            <tr>
              <th scope="row">
                {translate key="plugins.generic.swordv3.status.rejected"}
              </th>
              <td>
                {translate key="plugins.generic.swordv3.status.rejected.description"}
              </td>
              <td>{$rejected}</td>
            </tr>
            <tr>
              <th scope="row">
                {translate key="plugins.generic.swordv3.status.deleted"}
              </th>
              <td>
                {translate key="plugins.generic.swordv3.status.deleted.description"}
              </td>
              <td>{$deleted}</td>
            </tr>
            <tr>
              <th scope="row">
                {translate key="plugins.generic.swordv3.status.unknown"}
              </th>
              <td>
                {translate key="plugins.generic.swordv3.status.unknown.description"}
              </td>
              <td>{$unknown}</td>
            </tr>
          </table>
          <div class="flex items-center gap-4">
            <a href="{url page="swordv3" op="deposit"}" class="px-2 border rounded">
              Deposit
            </a>
            <a href="{url page="swordv3" op="csv"}" class="px-2 border rounded">
              Export to CSV
            </a>
            <a href="{url page="swordv3" op="redeposit"}" class="px-2 border rounded">
              Re-deposit All
            </a>
            <a href="{url page="swordv3" op="redeposit" path="rejected"}" class="px-2 border rounded">
              Re-deposit Rejected
            </a>
            <a href="{url page="swordv3" op="redeposit" path="deleted"}" class="px-2 border rounded">
              Re-deposit Deleted
            </a>
            <a href="{url page="swordv3" op="reset"}" class="px-2 border rounded">
              RESET
            </a>
          </div>
        </div>
      {/if}
    </tab>
    <tab id="swordv3/swordv3-settings" label="{translate key="navigation.setup"}">
			<pkp-form
				v-bind="components.swordv3service"
				@set="set"
			/>
    </tab>
  </tabs>
</tab>