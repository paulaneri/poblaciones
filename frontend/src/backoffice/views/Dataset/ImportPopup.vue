<template>
  <div>
    <md-dialog :md-active.sync="openImport">
      <md-dialog-title>Importar datos</md-dialog-title>

      <stepper ref="stepper" title="Importando datos" @closed="onCloseStepper"></stepper>
      <md-dialog-content>
        <div>
          <p>Seleccione el archivo que desea importar. Los tipos de archivo aceptados son:</p>
					<ul>
						<li>Archivos Excel (.xls, xlsx)</li>
						<li>Archivos de datos de SPSS (.sav)</li>
						<li>Archivos de texto separados por comas (.csv)</li>
					</ul>
					<!--
					https://poblaciones.org/wp-content/uploads/2019/11/Poblaciones-Como-convertir-shapefiles-a-CSV-con-QGIS.pdf
						-->
        </div>

        <div class="md-layout md-gutter">
          <div class="md-layout-item md-size-100">
            <vue-dropzone style="float:left"
              ref="myVueDropzone"
              @vdropzone-success="afterSuccess"
              @vdropzone-complete="afterComplete"
              @vdropzone-sending="beforeSending"
							@vdropzone-max-files-exceeded="maxfilesexceeded"
              id="dropzone"
              :options="dropzoneOptions"
            >
						</vue-dropzone>
						<md-button style="float:left;background-color: #ececec;" v-if="hasFiles" title="Quitar" class="md-icon-button" v-on:click="clear">
							<md-icon>close</md-icon>
						</md-button>
          </div>
					<div v-if="Dataset !== null && Dataset.Columns !== null && Dataset.Columns.length > 0" class="md-layout-item md-size-100" style="margin-top: -10px; margin-bottom: 12px;">
						<p>
							<md-switch class="md-primary" v-model="keepLabels">
								Mantener etiquetas de variables coincidentes

						<div class="mp-label" style="margin-top: 4px; margin-bottom: 4px">
							Cuando una variable sea de igual nombre que una existente, su etiqueta
							y las etiquetas de sus valores se conservarán. Si desea en cambio actualizarlos, desmarque 'Mantener etiquetas'.
						</div>
							</md-switch>
						</p>
					</div>

				</div>
      </md-dialog-content>
			<input-popup ref="datasetDialog" @selected="CreateDataset" />
			<invoker ref="invoker"></invoker>

      <md-dialog-actions>
        <md-button @click="openImport = false">Cancelar</md-button>
        <md-button class="md-primary" :disabled="sending" @click="save()">Aceptar</md-button>
      </md-dialog-actions>
    </md-dialog>
  </div>
</template>

<script>
import InputPopup from "@/backoffice/components/InputPopup";
import vueDropzone from "vue2-dropzone";
import "vue2-dropzone/dist/vue2Dropzone.min.css";
import h from '@/public/js/helper';

export default {
  name: "General",
	components: {
		vueDropzone,
		InputPopup
	},
  data() {
    return {
      openImport: false,
      extension: "",
      sending: false,
			hasFiles: false,
      bucketId: 0,
			keepLabels: true,
			saveRequested: false,
			createdDataset: null,
			forceCreateNewDataset: false,
			dropzoneOptions: {
        url: this.getCreateFileUrl,
        thumbnailWidth: 150,
        withCredentials: true,
				maxFiles: 1,
				acceptedFiles: '.csv,.txt,.sav,.xls,.xlsx',
				dictDefaultMessage: "Arrastre su archivo aquí o haga click para examinar.",
    		forceChunking: true,
		    chunking: true,
				chunkSize: 500000,
        chunksUploaded: function(file, done) {
          done();
        }
      }
    };
  },
  computed: {
    Work() {
      return window.Context.CurrentWork;
    },
    Dataset() {
			return (this.forceCreateNewDataset ? null : window.Context.CurrentDataset);
    },
  },
  methods: {
    getCreateFileUrl() {
      return this.Work.GetCreateFileUrl(this.getBucketId());
    },
    getBucketId() {
      return this.bucketId;
    },
    beforeSending(file) {
			this.extension = h.extractFileExtension(file.name);
			this.filename = h.extractFilename(file.name);
			this.createdDataset = null;
			this.sending = true;
    },
		maxfilesexceeded(file) {
			this.$refs.myVueDropzone.removeAllFiles();
			this.$refs.myVueDropzone.addFile(file);
		},
		clear() {
			this.$refs.myVueDropzone.removeAllFiles();
			this.generateBucketId();
			this.hasFiles = false;
		},
    afterSuccess(file, response) {
      this.sending = false;
      if (this.saveRequested) {
        this.save();
      }
    },
    afterComplete(file) {
      this.sending = false;
			this.hasFiles = true;
    },
		save() {
      var stepper = this.$refs.stepper;
      stepper.startUrl = this.Work.GetDatasetFileImportUrl(this.keepLabels);
      stepper.stepUrl = this.Work.GetStepDatasetFileImportUrl();
      let bucketId = this.getBucketId();
      let extension = this.extension;
			if (extension !== 'sav' && extension !== 'csv' && extension !== 'txt'
						&& extension !== 'xls' && extension !== 'xlsx') {
				alert('La extensión del archivo debe ser SAV, XLS, XLSX, CSV o TXT.');
				return;
			}
			if (!this.Dataset && !this.createdDataset) {
				this.RequestDataset();
				return;
			}
			let datasetId = (this.Dataset ? this.Dataset.properties.Id : this.createdDataset.Id);
			stepper.args = { b: bucketId, d: datasetId, fe: extension };
			let loc = this;
			stepper.Start().then(function() {
				loc.Work.WorkChanged();
				if (loc.Dataset) {
					loc.Dataset.ReloadProperties();
					loc.Dataset.ReloadColumns();
				} else {
					loc.$refs.invoker.do(window.Db, window.Db.RebindAndFocusLastDataset, loc.$router);
				}

			});
		},
		onCloseStepper(success) {
			if (success) {
				this.openImport = false;
			}
		},
		RequestDataset() {
			this.$refs.datasetDialog.show('Importar', 'Indique un nombre para el dataset',
								'', 'Ej. Escuelas primarias.', this.filename, 100);
		},
		CreateDataset(name) {
			var loc = this;
			this.$refs.invoker.do(this.Work,
				this.Work.CreateNewDataset,
				name.trim())
				.then(function (dataset) {
					loc.createdDataset = dataset;
					loc.save();
				});

		},
		generateBucketId() {
			this.bucketId = new Date().getTime() * 10000;
		},
		show(forceCreateNewDataset) {
			this.extension = '';
			this.generateBucketId();
			this.sending = false;
			this.hasFiles = false;
			this.openImport = true;
			this.createdDataset = null;
			this.forceCreateNewDataset = forceCreateNewDataset;
		}
	},
};
</script>

<style rel="stylesheet/scss" lang="scss" scoped>
.md-layout-item .md-size-25 {
  padding: 0 !important;
}
.hidden {
  display: none;
}

.visible {
  display: inline;
}
</style>

<style rel="stylesheet/scss" lang="scss">

#drop1 {
  padding: 6px;
}

.dz-preview {
	margin: 0px !important;
}
.dropzone {
	min-height: unset ! important;
	padding: 0px!important;
  width: 164px;
}

.dropzone .dz-preview {
  background: #666;
  height: 100px !important;
}
</style>
