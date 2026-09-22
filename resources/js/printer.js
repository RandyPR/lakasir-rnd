class Printer {
  constructor(optionsOrPrinterId, driver = 'usb', paperWidth = 58) {
    if (typeof optionsOrPrinterId === 'object' && optionsOrPrinterId !== null) {
      this.printerId = optionsOrPrinterId.printerId;
      this.driver = optionsOrPrinterId.driver || 'usb';
      this.paperWidth = parseInt(optionsOrPrinterId.paper_width || optionsOrPrinterId.paperWidth) || 58;
    } else {
      this.printerId = optionsOrPrinterId;
      this.driver = driver || 'usb';
      this.paperWidth = parseInt(paperWidth) || 58;
    }
    this.lineWidth = this.paperWidth === 80 ? 48 : 32;
    this.commands = '';
    this.bufferChunks = [];
  }

  addCommand(command) {
    this.commands += command;
    this.bufferChunks.push(command);
  }

  font(font) {
    const fonts = {
      'a': '\x1b\x4d\x00', // Font A
      'b': '\x1b\x4d\x01'  // Font B
    };
    this.addCommand(fonts[font] || fonts['a']);
    return this;
  }

  align(align) {
    const alignments = {
      'left': '\x1b\x61\x00',
      'center': '\x1b\x61\x01',
      'right': '\x1b\x61\x02'
    };
    this.addCommand(alignments[align] || alignments['left']);
    return this;
  }

  style(style) {
    const styles = {
      'bold': '\x1b\x45\x01',
      'underline': '\x1b\x2d\x01',
      'normal': '\x1b\x45\x00' + '\x1b\x2d\x00'
    };
    this.addCommand(styles[style ?? 'normal']);
    return this;
  }

  size(width, height) {
    this.addCommand('\x1d\x21' + String.fromCharCode((width << 4) | height));
    return this;
  }

  text(text) {
    this.addCommand(text + '\n');
    return this;
  }

  barcode(code, type) {
    const types = {
      'EAN8': '\x1d\x6b\x02'
    };
    this.addCommand((types[type] || types['EAN8']) + code + '\x00');
    return this;
  }

  table(data) {
    let row = '';
    const totalTextLength = data.reduce((sum, text) => sum + String(text).length, 0);
    const totalPadding = Math.max(1, this.lineWidth - totalTextLength);

    data.forEach((text, index) => {
      row += text;
      if (index < data.length - 1) {
        for (let i = 0; i < totalPadding; i++) {
          row += ' ';
        }
      }
    });

    this.addCommand(row.trim() + '\x0A');
    return this;
  }

  tableCustom(data) {
    const totalTextLength = data.reduce((sum, cell) => sum + String(cell.text).length, 0);
    const totalPadding = Math.max(1, this.lineWidth - totalTextLength);
    let row = '';

    data.forEach((cell, index) => {
      let style = cell.style === 'B' ? '\x1b\x45\x01' : '\x1b\x45\x00';
      row += style + cell.text;
      if (index < data.length - 1) {
        for (let i = 0; i < totalPadding; i++) {
          row += ' ';
        }
      }
    });

    this.addCommand(row.trim() + '\x0A');
    return this;
  }

  newLine(line = 1) {
    for (let i = 0; i < line; i++) {
      this.text('');
    }
    return this;
  }

  cut() {
    this.addCommand('\n\n' + '\x1d\x56\x00');
    return this;
  }

  async image(imageSrc, maxWidth = null) {
    if (!imageSrc) return this;

    const paperMaxDots = this.paperWidth === 80 ? 576 : 384;
    const targetMaxWidth = maxWidth ? Math.min(maxWidth, paperMaxDots) : paperMaxDots;

    return new Promise((resolve) => {
      const img = new Image();
      img.crossOrigin = 'Anonymous';
      img.onload = () => {
        let width = img.width;
        let height = img.height;

        if (width > targetMaxWidth) {
          height = Math.round((height * targetMaxWidth) / width);
          width = targetMaxWidth;
        }

        width = Math.floor(width / 8) * 8;
        if (width <= 0) width = 8;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, height);
        ctx.drawImage(img, 0, 0, width, height);

        const imgData = ctx.getImageData(0, 0, width, height);
        const pixels = imgData.data;

        const gray = new Float32Array(width * height);
        for (let i = 0; i < pixels.length; i += 4) {
          const r = pixels[i];
          const g = pixels[i + 1];
          const b = pixels[i + 2];
          const a = pixels[i + 3];
          if (a < 128) {
            gray[i / 4] = 255;
          } else {
            gray[i / 4] = 0.299 * r + 0.587 * g + 0.114 * b;
          }
        }

        const bytesPerLine = width / 8;
        const rasterBytes = new Uint8Array(bytesPerLine * height);

        for (let y = 0; y < height; y++) {
          for (let x = 0; x < width; x++) {
            const idx = y * width + x;
            const oldVal = gray[idx];
            const newVal = oldVal < 128 ? 0 : 255;
            const error = oldVal - newVal;

            if (newVal === 0) {
              const byteIdx = y * bytesPerLine + Math.floor(x / 8);
              const bitPos = 7 - (x % 8);
              rasterBytes[byteIdx] |= (1 << bitPos);
            }

            if (x + 1 < width) gray[idx + 1] += (error * 7) / 16;
            if (x - 1 >= 0 && y + 1 < height) gray[idx + width - 1] += (error * 3) / 16;
            if (y + 1 < height) gray[idx + width] += (error * 5) / 16;
            if (x + 1 < width && y + 1 < height) gray[idx + width + 1] += (error * 1) / 16;
          }
        }

        const xL = bytesPerLine & 0xff;
        const xH = (bytesPerLine >> 8) & 0xff;
        const yL = height & 0xff;
        const yH = (height >> 8) & 0xff;

        const header = new Uint8Array([
          0x1b, 0x61, 0x01, // Center align
          0x1d, 0x76, 0x30, 0x00, xL, xH, yL, yH
        ]);

        const fullImageCmd = new Uint8Array(header.length + rasterBytes.length + 3);
        fullImageCmd.set(header, 0);
        fullImageCmd.set(rasterBytes, header.length);
        fullImageCmd.set([0x0a, 0x1b, 0x61, 0x00], header.length + rasterBytes.length);

        this.bufferChunks.push(fullImageCmd);
        this.logoImageSrc = imageSrc;
        resolve(this);
      };

      img.onerror = (err) => {
        console.error('Failed to load image for receipt printing:', err);
        resolve(this);
      };

      img.src = imageSrc;
    });
  }

  getBinaryData() {
    const encoder = new TextEncoder();
    const parts = this.bufferChunks.map(chunk => {
      if (chunk instanceof Uint8Array) {
        return chunk;
      }
      return encoder.encode(chunk);
    });

    const totalLength = parts.reduce((acc, curr) => acc + curr.length, 0);
    const result = new Uint8Array(totalLength);
    let offset = 0;
    for (const part of parts) {
      result.set(part, offset);
      offset += part.length;
    }
    return result;
  }

  getCommands() {
    return this.commands;
  }

  clearCommands() {
    this.commands = '';
    this.bufferChunks = [];
    this.logoImageSrc = null;
  }

  showNoPrinterNotification() {
    console.warn('No printer found with specified ID');
    if (typeof FilamentNotification !== 'undefined') {
      new FilamentNotification()
        .title('You should choose the printer first in printer setting')
        .danger()
        .actions([
          new FilamentNotificationAction('Setting')
            .icon('heroicon-o-cog-6-tooth')
            .button()
            .url('/member/printer'),
        ])
        .send();
    }
  }

  async print() {
    if (window._lakasirIsPrintingNow) {
      console.warn('Another print job is currently in progress, skipping duplicate print call.');
      return;
    }
    window._lakasirIsPrintingNow = true;

    try {
      const data = this.getBinaryData();
      console.log(`Printing via driver '${this.driver}', total bytes: ${data.length}`);

      if (this.driver === 'serial') {
        await this.printToSerial(data);
      } else if (this.driver === 'bluetooth') {
        await this.printToBluetooth(data);
      } else if (this.driver === 'browser') {
        this.printToBrowser();
      } else {
        await this.printToUSB(data);
      }
    } finally {
      this.clearCommands();
      setTimeout(() => {
        window._lakasirIsPrintingNow = false;
      }, 2500);
    }
  }

  printToBrowser() {
    const textContent = this.commands;
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow.document;
    const widthMm = this.paperWidth === 80 ? '72mm' : '48mm';
    const logoHtml = this.logoImageSrc ? `<div style="text-align: center; margin-bottom: 8px;"><img src="${this.logoImageSrc}" style="max-width: 100%; max-height: 80px; display: inline-block;" /></div>` : '';

    doc.open();
    doc.write(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>Receipt Print</title>
        <style>
          @page { size: ${this.paperWidth}mm auto; margin: 0; }
          body {
            width: ${widthMm};
            margin: 0 auto;
            padding: 5px 0;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            line-height: 1.2;
            color: #000;
            white-space: pre-wrap;
            word-break: break-all;
          }
        </style>
      </head>
      <body>${logoHtml}${textContent.replace(/[\x00-\x1F\x7F-\x9F]/g, '')}</body>
      </html>
    `);
    doc.close();
    iframe.contentWindow.focus();
    setTimeout(() => {
      iframe.contentWindow.print();
      setTimeout(() => {
        if (iframe.parentNode) {
          document.body.removeChild(iframe);
        }
      }, 1000);
    }, 250);
    this.clearCommands();
  }

  async printToSerial(data) {
    try {
      if (typeof navigator.serial === 'undefined') {
        if (typeof window !== 'undefined' && !window.isSecureContext) {
          throw new Error('Web Serial API diblokir oleh browser karena situs tidak dibuka via HTTPS atau http://localhost.');
        }
        throw new Error('Web Serial API tidak didukung di browser ini. Gunakan Google Chrome atau Microsoft Edge di PC/Laptop.');
      }

      let port = window.lakasirSerialPort;

      if (!port || !port.readable) {
        if (navigator.serial.getPorts) {
          const ports = await navigator.serial.getPorts();
          if (ports.length > 0) {
            port = ports[0];
          }
        }
      }

      if (!port) {
        port = await navigator.serial.requestPort();
      }

      if (!port) {
        this.showNoPrinterNotification();
        return;
      }

      window.lakasirSerialPort = port;

      if (!port.readable) {
        try {
          await port.open({ baudRate: 9600 });
        } catch (openErr) {
          if (!openErr.message || !openErr.message.includes('already open')) {
            throw openErr;
          }
        }
      }

      const writer = port.writable.getWriter();
      try {
        await writer.write(data);
      } finally {
        writer.releaseLock();
      }

      console.log('Data successfully sent via Web Serial');
      this.clearCommands();
    } catch (e) {
      console.error('Serial print error:', e);
      if (typeof FilamentNotification !== 'undefined') {
        new FilamentNotification()
          .title('Gagal mencetak via Serial / Bluetooth COM: ' + (e.message || e))
          .danger()
          .send();
      }
    }
  }

  async printToUSB(data) {
    try {
      if (typeof navigator.usb === 'undefined') {
        if (typeof window !== 'undefined' && !window.isSecureContext) {
          throw new Error('WebUSB API diblokir oleh browser karena situs tidak dibuka via HTTPS atau http://localhost (Secure Context dibutuhkan).');
        }
        throw new Error('WebUSB API tidak didukung di browser ini.');
      }

      const devices = await navigator.usb.getDevices();
      const device = devices.find(d => 
        String(d.vendorId) === String(this.printerId) || d.vendorId === this.printerId
      );
      if (device) {
        await device.open();
        await device.selectConfiguration(1);
        await device.claimInterface(0);

        const endpoint = device.configuration.interfaces[0].alternate.endpoints.filter(endpoint => endpoint.direction === 'out')[0];
        await device.transferOut(endpoint.endpointNumber, data);
        await device.close();
        console.log('Data successfully sent to USB printer');
        this.clearCommands();
      } else {
        this.showNoPrinterNotification();
      }
    } catch (e) {
      console.error('USB print error:', e);
      if (typeof FilamentNotification !== 'undefined') {
        new FilamentNotification()
          .title('Gagal mencetak ke USB printer: ' + (e.message || e))
          .danger()
          .send();
      }
    }
  }

  async printToBluetooth(data) {
    try {
      if (typeof navigator.bluetooth === 'undefined') {
        if (typeof window !== 'undefined' && !window.isSecureContext) {
          throw new Error('Web Bluetooth API diblokir oleh browser karena situs tidak dibuka via HTTPS atau http://localhost (Secure Context dibutuhkan).');
        }
        throw new Error('Web Bluetooth API tidak didukung di browser ini. Gunakan Google Chrome atau Microsoft Edge.');
      }

      const bleServiceUuids = [
        '000018f0-0000-1000-8000-00805f9b34fb',
        '0000ffe0-0000-1000-8000-00805f9b34fb',
        '0000fff0-0000-1000-8000-00805f9b34fb',
        '0000ff00-0000-1000-8000-00805f9b34fb',
        '0000ae30-0000-1000-8000-00805f9b34fb',
        '0000fee7-0000-1000-8000-00805f9b34fb',
        '0000fee0-0000-1000-8000-00805f9b34fb',
        'e7810a71-73ae-499d-8c15-faa9aef0c3f1',
        '49535343-fe7d-4ae5-8fa9-9fafd205e455',
        '00001101-0000-1000-8000-00805f9b34fb',
      ];

      let device = window.lakasirBluetoothDevice;
      let server = window.lakasirBluetoothServer;

      // 1. If not connected, find device or reuse cached
      if (!device || !device.gatt?.connected || !server) {
        server = null;
        if (!device && navigator.bluetooth.getDevices) {
          try {
            const devices = await navigator.bluetooth.getDevices();
            if (devices && devices.length > 0) {
              device = devices.find(d => d.id === this.printerId) || devices[0];
            }
          } catch (e) {
            console.warn('getDevices() warning:', e);
          }
        }

        // 2. Try GATT connection with the found/cached device
        if (device) {
          try {
            if (device.gatt?.connected) {
              try { device.gatt.disconnect(); } catch (_) {}
              await new Promise(r => setTimeout(r, 100));
            }
            console.log('Connecting to Bluetooth device:', device.name || device.id);
            server = await device.gatt.connect();
          } catch (connectErr) {
            console.warn('Direct connect to cached device failed:', connectErr.message);
            server = null;
            device = null;
          }
        }

        // 3. If still no server/device, prompt user to select the printer
        if (!server || !device) {
          console.log('Requesting Bluetooth device via browser picker...');
          try {
            device = await navigator.bluetooth.requestDevice({
              acceptAllDevices: true,
              optionalServices: bleServiceUuids,
            });
            if (device) {
              if (device.gatt?.connected) {
                try { device.gatt.disconnect(); } catch (_) {}
                await new Promise(r => setTimeout(r, 100));
              }
              server = await device.gatt.connect();
            }
          } catch (reqErr) {
            console.error('requestDevice error:', reqErr);
            if (reqErr.name === 'NotFoundError') {
              // User cancelled picker
              return;
            }
            throw reqErr;
          }
        }

        if (!device || !server) {
          this.showNoPrinterNotification();
          return;
        }

        window.lakasirBluetoothDevice = device;
        window.lakasirBluetoothServer = server;

        if (!device._lakasirDisconnectListenerAdded) {
          device.addEventListener('gattserverdisconnected', () => {
            console.log('Bluetooth printer disconnected');
            window.lakasirBluetoothServer = null;
          });
          device._lakasirDisconnectListenerAdded = true;
        }
      }

      // 4. Find writable characteristic across all services
      let writeChar = null;
      const services = await server.getPrimaryServices();

      for (const service of services) {
        try {
          const chars = await service.getCharacteristics();
          for (const char of chars) {
            if (char.properties.writeWithoutResponse || char.properties.write) {
              writeChar = char;
              break;
            }
          }
        } catch (err) {
          // Continue scanning services
        }
        if (writeChar) break;
      }

      if (!writeChar) {
        throw new Error('Tidak ditemukan characteristic tulis BLE pada printer ini. Pastikan printer menyala dan coba ulang.');
      }

      // 5. Send data in small chunks for Bluetooth BLE MTU
      const CHUNK_SIZE = 20;
      const CHUNK_DELAY = 50;
      for (let offset = 0; offset < data.length; offset += CHUNK_SIZE) {
        const chunk = data.slice(offset, offset + CHUNK_SIZE);
        if (writeChar.properties.writeWithoutResponse) {
          await writeChar.writeValueWithoutResponse(chunk);
        } else {
          await writeChar.writeValue(chunk);
        }
        if (offset + CHUNK_SIZE < data.length) {
          await new Promise(r => setTimeout(r, CHUNK_DELAY));
        }
      }

      console.log('Data successfully sent to Bluetooth printer');
      this.clearCommands();
    } catch (e) {
      console.error('Bluetooth print error:', e);
      window.lakasirBluetoothServer = null;
      window.lakasirBluetoothDevice = null;
      if (typeof FilamentNotification !== 'undefined') {
        new FilamentNotification()
          .title('Gagal mencetak ke Bluetooth printer: ' + (e.message || e))
          .danger()
          .send();
      }
    }
  }
}
