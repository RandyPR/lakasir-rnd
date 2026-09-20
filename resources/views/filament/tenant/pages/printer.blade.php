<x-filament-panels::page>
  <div x-data="printer">
    {{-- Secure context warning banner --}}
    <template x-if="!isSecure">
      <div class="mb-6 p-4 rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200 text-sm shadow-sm">
        <div class="font-semibold flex items-center gap-2 text-base text-amber-800 dark:text-amber-300">
          <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-amber-600 dark:text-amber-400" />
          <span>Penting: Browser Membutuhkan HTTPS (Secure Context)</span>
        </div>
        <p class="mt-2">
          Google Chrome & Microsoft Edge mematikan fitur <strong>WebUSB</strong> dan <strong>Web Bluetooth</strong> jika website dibuka melalui protokol <strong>HTTP</strong> biasa (bukan HTTPS dan bukan localhost).
        </p>
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
          URL saat ini: <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-900/60 rounded" x-text="window.location.origin"></code> (Status: Insecure Context)
        </p>
        <div class="mt-3 pt-2 border-t border-amber-200 dark:border-amber-800/50">
          <p class="font-medium text-xs uppercase tracking-wider mb-1">Cara Mengaktifkannya di Chrome:</p>
          <ol class="list-decimal list-inside space-y-1 text-xs">
            <li>Buka tab baru di Chrome, ketik: <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-900/60 font-mono rounded">chrome://flags/#unsafely-treat-insecure-origin-as-secure</code></li>
            <li>Aktifkan (pilih <strong>Enabled</strong>) dan masukkan origin: <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-900/60 font-mono rounded" x-text="window.location.origin"></code></li>
            <li>Klik tombol <strong>Relaunch</strong> di pojok kanan bawah Chrome.</li>
            <li><em>Atau</em> jalankan aplikasi menggunakan <strong>HTTPS (SSL)</strong> atau via <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-900/60 font-mono rounded">http://localhost</code>.</li>
          </ol>
        </div>
      </div>
    </template>

    {{-- Logo upload and preview card --}}
    <div class="mb-6 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
      <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-1">@lang('Receipt Logo / Image')</h3>
      <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        @lang('Upload a logo to print at the top of your thermal receipt. The image will be converted to monochrome for thermal printing.')
      </p>

      <div class="flex flex-wrap items-center gap-4">
        <template x-if="logoPreview">
          <div class="flex items-center gap-3 border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-gray-50 dark:bg-gray-900">
            <img :src="logoPreview" alt="Receipt Logo Preview" class="max-h-24 max-w-[200px] object-contain rounded" />
            <button
              type="button"
              x-on:click="removeLogo"
              class="text-xs text-red-600 dark:text-red-400 hover:underline flex items-center gap-1 font-medium">
              <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
              @lang('Remove Logo')
            </button>
          </div>
        </template>

        <div>
          <label class="cursor-pointer inline-flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-sm font-medium rounded-lg text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-600 transition">
            <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5" />
            <span x-text="logoPreview ? '@lang('Change Logo')' : '@lang('Upload Logo')'"></span>
            <input type="file" accept="image/*" class="hidden" x-on:change="handleLogoUpload($event)" />
          </label>
        </div>
      </div>
    </div>

    <x-filament-panels::form
      x-ref="printerForm"
      id="form"
      wire:key="{{ 'forms.' . $this->getFormStatePath() }}">
      {{ $this->form }}

    <x-filament-panels::form.actions
      :actions="$this->getCachedFormActions()"
      :full-width="$this->hasFullWidthFormActions()"
      />
    </x-filament-panels::form>
  </div>
</x-filament-panels::page>
@script()
  <script>
    Alpine.data('printer', () => ({
      logoPreview: null,
      isSecure: window.isSecureContext,
      init() {
        if ($wire.data.logo) {
          this.logoPreview = $wire.data.logo;
        }

        if (localStorage.printer) {
          try {
            const printer = JSON.parse(localStorage.printer);
            $wire.data = {
              ...$wire.data,
              ...printer
            };
            if (printer.logo) {
              this.logoPreview = printer.logo;
              $wire.data.logo = printer.logo;
            }
          } catch (e) {
            console.error('Error reading localStorage.printer:', e);
          }
        }
      },
      handleLogoUpload(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
          this.logoPreview = e.target.result;
          $wire.data.logo = e.target.result;
        };
        reader.readAsDataURL(file);
      },
      removeLogo() {
        this.logoPreview = null;
        $wire.data.logo = null;
      },
      fetchDeviceByDriver() {
        const driverInput = document.querySelector('[name="data.driver"]');
        const driver = driverInput ? driverInput.value : ($wire.data.driver || 'serial');
        $wire.data.driver = driver;

        if (driver === 'serial') {
          this.fetchSerial();
        } else if (driver === 'bluetooth') {
          this.fetchBluetooth();
        } else if (driver === 'browser') {
          this.fetchBrowser();
        } else {
          this.fetchTheUsb();
        }
      },
      fetchBrowser() {
        $wire.data.printer = 'Windows / Browser System Print';
        $wire.data.printerId = 'browser-system';
      },
      async fetchSerial() {
        if (!window.isSecureContext) {
          alert('Web Serial membutuhkan Secure Context (HTTPS atau http://localhost).\n\nOrigin saat ini: ' + window.location.origin + '\n\nSilakan buka via HTTPS atau aktifkan flag di Chrome: chrome://flags/#unsafely-treat-insecure-origin-as-secure');
          return;
        }
        if (!navigator.serial) {
          alert('@lang('Web Serial API tidak didukung di browser ini. Silakan gunakan Google Chrome atau Microsoft Edge di PC/Laptop.')');
          return;
        }
        try {
          const port = await navigator.serial.requestPort();
          const info = port.getInfo();
          const name = info.usbVendorId ? `Serial Device (VID: 0x${info.usbVendorId.toString(16)})` : 'Bluetooth / USB Serial COM Port';
          $wire.data.printer = name;
          $wire.data.printerId = info.usbVendorId ? String(info.usbVendorId) : 'serial-com';
          window.lakasirSerialPort = port;
          console.log('Serial port selected:', info);
        } catch (error) {
          console.error('Serial port select error:', error);
        }
      },
      async fetchTheUsb() {
        if (!window.isSecureContext) {
          alert('WebUSB diblokir oleh browser karena koneksi BUKAN Secure Context (HTTPS).\n\nOrigin saat ini: ' + window.location.origin + '\n\nChrome hanya mengizinkan WebUSB & Web Bluetooth pada HTTPS atau http://localhost.\n\nSilakan gunakan HTTPS atau aktifkan flag di Chrome: chrome://flags/#unsafely-treat-insecure-origin-as-secure');
          return;
        }
        if (!navigator.usb) {
          alert('@lang('WebUSB API not supported in this browser')');
          return;
        }
        let selectedDevice = null;
        try {
          selectedDevice = await navigator.usb.requestDevice({ filters: [] });
          await selectedDevice.open();
          await selectedDevice.selectConfiguration(1);
          await selectedDevice.claimInterface(0);
          $wire.data.printer = selectedDevice.productName || 'USB Printer';
          $wire.data.printerId = selectedDevice.vendorId;
          console.log('USB printer selected:', selectedDevice.productName);
        } catch (error) {
          console.error(error);
        }
      },
      async fetchBluetooth() {
        if (!window.isSecureContext) {
          alert('Web Bluetooth diblokir oleh browser karena koneksi BUKAN Secure Context (HTTPS).\n\nOrigin saat ini: ' + window.location.origin + '\n\nChrome hanya mengizinkan Web Bluetooth pada HTTPS atau http://localhost.\n\nSilakan gunakan HTTPS atau aktifkan flag di Chrome: chrome://flags/#unsafely-treat-insecure-origin-as-secure');
          return;
        }
        if (!navigator.bluetooth) {
          alert('@lang('Web Bluetooth API tidak didukung di browser ini. Silakan gunakan Google Chrome atau Microsoft Edge.')');
          return;
        }
        try {
          const device = await navigator.bluetooth.requestDevice({
            acceptAllDevices: true,
            optionalServices: [
              '000018f0-0000-1000-8000-00805f9b34fb',
              'e7810a71-73ae-499d-8c15-faa9aef0c3f1',
              '49535343-fe7d-4ae5-8fa9-9fafd205e455',
              '0000ff00-0000-1000-8000-00805f9b34fb',
              '0000ae30-0000-1000-8000-00805f9b34fb',
            ]
          });

          if (device) {
            $wire.data.printer = device.name || 'Bluetooth Printer (RPP02N / iware)';
            $wire.data.printerId = device.id;
            window.lakasirBluetoothDevice = device;
            console.log('Bluetooth printer paired:', device.name, device.id);
          }
        } catch (error) {
          console.error('Bluetooth pair error:', error);
        }
      },
      async save() {
        $wire.validateInput();
        if(!$wire.data.printer || !$wire.data.name) {
          return;
        }

        const driverInput = document.querySelector('[name="data.driver"]');
        const driver = driverInput ? driverInput.value : ($wire.data.driver || 'usb');
        const paperWidthInput = document.querySelector('[name="data.paper_width"]');
        const paperWidth = paperWidthInput ? paperWidthInput.value : ($wire.data.paper_width || '58');

        $wire.data.driver = driver;
        $wire.data.paper_width = paperWidth;

        const printerConfig = {
          ...$wire.data,
          driver: driver,
          paper_width: paperWidth,
          logo: this.logoPreview,
        };

        localStorage.setItem("printer", JSON.stringify(printerConfig));

        await $wire.saveToServer({
          header: $wire.data.header,
          footer: $wire.data.footer,
          paper_width: paperWidth,
          logo: this.logoPreview,
          driver: driver,
        });

        new FilamentNotification()
          .title('@lang('Save success')')
          .success()
          .send();
      },
      async test() {
        $wire.validateInput();
        if(!$wire.data.printer || !$wire.data.name) {
          return;
        }
        try {
          const driverInput = document.querySelector('[name="data.driver"]');
          const driver = driverInput ? driverInput.value : ($wire.data.driver || 'usb');
          const paperWidthInput = document.querySelector('[name="data.paper_width"]');
          const paperWidth = paperWidthInput ? paperWidthInput.value : ($wire.data.paper_width || '58');

          const printerConfig = {
            ...$wire.data,
            driver: driver,
            paper_width: paperWidth,
            logo: this.logoPreview,
          };
          const printer = new Printer(printerConfig);
          let printerAction = printer;

          if (this.logoPreview) {
            await printerAction.image(this.logoPreview, paperWidth || 58);
          }

          printerAction.font('a')
            .size(1)
            .align('center')
            .text('Toko Mitra Susu')
            .size(0)
            .text('Jl. cipinang raya no 156');

          if($wire.data.header != undefined && $wire.data.header) {
            printerAction
              .text($wire.data.header);
          }

          printerAction.align('left')
            .text('-------------------------------')
            .table(['Cashier', 'Nama kasir'])
            .table(['Customer', 'Budi Santoso'])
            .table(['Payment method', 'Cash'])
            .text('-------------------------------')
            .tableCustom([
              { text: 'Test 1'},
              { text: moneyFormat(2000) + ' x 1', style: 'B'}
            ])
            .align('right')
            .text(moneyFormat(2000))
            .tableCustom([
              { text: 'Test 2'},
              { text: moneyFormat(5000) + ' x 1', style: 'B'}
            ])
            .align('right')
            .text(moneyFormat(5000))
            .text('-------------------------------')
            .tableCustom([
              { text: 'Subtotal', style: 'B'},
              { text: moneyFormat(7000), style: 'B'}
            ])
            .tableCustom([
              { text: 'Tax', style: 'B'},
              { text: moneyFormat(0), style: 'B'}
            ])
            .tableCustom([
              { text: 'Total price', style: 'B'},
              { text: moneyFormat(7000), style: 'B'}
            ])
            .newLine()
            .align('center');

          if($wire.data.footer != undefined && $wire.data.footer) {
            printerAction
              .text($wire.data.footer);
          }

          await printerAction.cut()
            .print();
        } catch (e) {
          console.error('Test print error:', e);
        }
      }
    }))
  </script>
@endscript
