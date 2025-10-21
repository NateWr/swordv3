<tab id="swordv3" label="{translate key="plugins.generic.swordv3.name"}">
  <tabs :is-side-tabs="true" :track-history="true">
    <tab id="swordv3/swordv3-deposits" label="Deposit">
      <div class="flex flex-col gap-4">
        <table style="text-align: left;">
          <tr>
            <th scope="row">Success</th>
            <td>{$success}</td>
          </tr>
          <tr>
            <th scope="row">Rejected</th>
            <td>{$rejected}</td>
          </tr>
          <tr>
            <th scope="row">Other</th>
            <td>{$other}</td>
          </tr>
          <tr style="border-top: 1px solid">
            <th scope="row">Total</th>
            <td>{$total}</td>
          </tr>
        </table>
        <div>
          <a href="{url page="swordv3" op="deposit"}">
            Deposit All
          </a>
        </div>
      </div>
    </tab>
    <tab id="swordv3/swordv3-settings" label="{translate key="navigation.setup"}">
			<pkp-form
				v-bind="components.swordv3service"
				@set="set"
			/>
    </tab>
  </tabs>
</tab>