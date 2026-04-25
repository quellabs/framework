<?php
	
	namespace Quellabs\Canvas\Loom\Renderer\Field;
	
	/**
	 * Renders an async file upload field as a <waka-file> custom element.
	 *
	 * The full DOM is rendered as static HTML by PHP and hydrated by wakaPAC.
	 * All event wiring (file picker trigger, file selection, remove) is done
	 * via data-pac-bind — no custom event listeners in the generated script.
	 *
	 * Upload logic lives in the wakaPAC abstraction: files are uploaded
	 * immediately on selection via wakaSync, and the reactive files array
	 * drives the UI. A computed doneFiles property feeds a separate foreach
	 * that renders hidden inputs for form submission — only successfully
	 * uploaded files participate in the submit.
	 *
	 * Server contract:
	 *   Success: 2xx  + JSON { id, name, size }
	 *   Failure: non-2xx + optional JSON { error: 'message' }
	 */
	class FileRenderer extends AbstractInputRenderer {
		
		/**
		 * @inheritDoc
		 * Not used directly — FieldRenderer calls renderWithScript() for file fields.
		 */
		public function renderInput(
			string $id,
			string $name,
			string $value,
			array  $properties,
			string $pacField,
			string $pacBind
		): string {
			return $this->buildElement($id, $name, $properties);
		}
		
		/**
		 * Returns both the static HTML and the wakaPAC initialisation script.
		 * Called directly by FieldRenderer, bypassing the standard renderInput() path.
		 *
		 * @param string $id
		 * @param string $name
		 * @param array  $properties
		 * @return array{html: string, script: string}
		 */
		public function renderWithScript(string $id, string $name, array $properties): array {
			return [
				'html'   => $this->buildElement($id, $name, $properties),
				'script' => $this->buildScript($id, $name, $properties),
			];
		}
		
		/**
		 * Build the static HTML for the file upload component.
		 * All interactivity is wired via data-pac-bind — no inline handlers.
		 *
		 * Two foreach loops:
		 * - files      — renders the visible file list (all statuses)
		 * - doneFiles  — renders hidden inputs for form submission (done only)
		 *
		 * @param string $id
		 * @param string $name
		 * @param array  $properties
		 * @return string
		 */
		protected function buildElement(string $id, string $name, array $properties): string {
			$nameAttr     = $this->e($name);
			$uploadUrl    = $this->e($properties['upload_url'] ?? '');
			$multipleAttr = !empty($properties['multiple']) ? ' multiple' : '';
			$disabledAttr = !empty($properties['disabled']) ? ' disabled' : '';
			$triggerLabel = !empty($properties['multiple']) ? 'Add files' : 'Add file';
			
			return <<<HTML
<div class="waka-file-widget" data-pac-id="{$id}" data-upload-url="{$uploadUrl}" name="{$nameAttr}"{$disabledAttr}>
    <input type="file" class="waka-file-input"{$multipleAttr}
           style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0,0,0,0)"
           data-pac-bind="change: onFileSelected">
    <div class="waka-file-header">
        <span class="waka-file-count">{{files.length > 0 ? files.length + ' file' + (files.length === 1 ? '' : 's') : ''}}</span>
    <button type="button" class="waka-file-trigger"{$disabledAttr}
            data-pac-bind="click: onTrigger">{$triggerLabel}</button>
    </div>
    <ul class="waka-file-list" data-pac-bind="foreach: files">
        <li class="waka-file-item" data-pac-bind="class: 'waka-file-item--' + item.status">
            <span class="waka-file-info">{{item.name}} — {{formatSize(item.size)}}</span>
            <span class="waka-file-status" data-pac-bind="visible: item.status === 'uploading'">↑</span>
            <span class="waka-file-error" data-pac-bind="visible: item.status === 'error'">{{item.error || 'Upload failed'}}</span>
            <button type="button" class="waka-file-remove"
                    data-pac-bind="visible: item.status !== 'uploading', click: remove(item.uid)">×</button>
        </li>
    </ul>
    <span data-pac-bind="foreach: doneFiles">
        <input type="hidden" name="{$nameAttr}[]" data-pac-bind="value: item.id">
    </span>
</div>
HTML;
		}
		
		/**
		 * Build the wakaPAC initialisation IIFE.
		 *
		 * The abstraction exposes:
		 * - files[]        Reactive array of { uid, id, name, size, status, error }
		 * - doneFiles      Computed — files filtered to status === 'done'
		 * - onTrigger()    Clicks the hidden file input to open the picker
		 * - onFileSelected() Called by the file input change binding
		 * - remove(uid)    Removes a file entry from the list
		 * - formatSize()   Byte formatter used in the foreach template
		 *
		 * Upload uses wakaSync.request() with validateStatus returning true so
		 * we always receive the raw Response and can read the body once cleanly.
		 *
		 * @param string $id
		 * @param string $name
		 * @param array  $properties
		 * @return string
		 */
		protected function buildScript(string $id, string $name, array $properties): string {
			return <<<JS
(function() {
    let nextUid = 0;
    
    wakaPAC('{$id}', {
        files: [],
        
        computed: {
            doneFiles() {
                return this.files.filter(function(f) { return f.status === 'done'; });
            }
        },
        
        onTrigger() {
            this.container.querySelector('input[type="file"]').click();
        },
        
        onFileSelected(event) {
            const self = this;
            
            Array.from(event.target.files || []).forEach(function(file) {
                self.upload(file);
            });
            
            event.target.value = '';
        },
        
        remove(uid) {
            this.files = this.files.filter(function(f) { return f.uid !== uid; });
        },
        
        formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },
        
        upload(file) {
            const uid     = ++nextUid;
            const self    = this;
            const url     = this.container.dataset.uploadUrl;
            const formData = new FormData();
            formData.append('file', file);
            
            this.files = this.files.concat([{
                uid:    uid,
                id:     null,
                name:   file.name,
                size:   file.size,
                status: 'uploading',
                error:  null
            }]);
            
            wakaPAC.sendMessageToParent('{$id}', wakaPAC.MSG_USER, 0, 0, {
                event: 'upload_start',
                name:  file.name,
                size:  file.size
            });
            
            wakaSync.request(url, {
                method:         'POST',
                data:           formData,
                validateStatus: function() { return true; },
                responseType:   'response'
            }).then(function(response) {
                if (response.ok) {
                    return response.json().then(function(data) {
                        self.files = self.files.map(function(f) {
                            return f.uid === uid
                                ? { uid: uid, id: data.id, name: file.name, size: file.size, status: 'done', error: null }
                                : f;
                        });
                        
                        wakaPAC.sendMessageToParent('{$id}', wakaPAC.MSG_USER, 0, 0, {
                            event: 'upload_success',
                            id:    data.id,
                            name:  file.name,
                            size:  file.size
                        });
                    });
                }
                
                const ct = response.headers.get('content-type') || '';
                const bodyPromise = /application\/(.+\+)?json/i.test(ct)
                    ? response.json().then(function(d) { return (d && typeof d.error === 'string') ? d.error : null; }).catch(function() { return null; })
                    : Promise.resolve(null);
                
                return bodyPromise.then(function(errorMessage) {
                    self.files = self.files.map(function(f) {
                        return f.uid === uid
                            ? { uid: uid, id: null, name: file.name, size: file.size, status: 'error', error: errorMessage }
                            : f;
                    });
                    
                    wakaPAC.sendMessageToParent('{$id}', wakaPAC.MSG_USER, 0, 0, {
                        event: 'upload_error',
                        name:  file.name,
                        size:  file.size,
                        error: errorMessage
                    });
                });
            }).catch(function() {
                self.files = self.files.map(function(f) {
                    return f.uid === uid
                        ? { uid: uid, id: null, name: file.name, size: file.size, status: 'error', error: 'Network error' }
                        : f;
                });
                
                wakaPAC.sendMessageToParent('{$id}', wakaPAC.MSG_USER, 0, 0, {
                    event: 'upload_error',
                    name:  file.name,
                    size:  file.size,
                    error: 'Network error'
                });
            });
        }
    });
})();
JS;
		}
	}