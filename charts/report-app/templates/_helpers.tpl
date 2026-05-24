{{/*
Common names + labels. Pattern lifted from the `helm create` default and
trimmed for this chart.
*/}}

{{- define "report-app.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "report-app.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "report-app.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "report-app.labels" -}}
helm.sh/chart: {{ include "report-app.chart" . }}
{{ include "report-app.selectorLabels" . }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{- define "report-app.selectorLabels" -}}
app.kubernetes.io/name: {{ include "report-app.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{/*
Component-scoped helpers — used by per-component templates so each
Deployment/Service gets a distinct selector.
*/}}

{{- define "report-app.componentLabels" -}}
{{ include "report-app.labels" .root }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "report-app.componentSelectorLabels" -}}
{{ include "report-app.selectorLabels" .root }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{/*
Common env vars pulled from ConfigMap + Secret. Used by every app/worker/
scheduler/migrations pod so they don't drift apart.
*/}}
{{- define "report-app.envFrom" -}}
- configMapRef:
    name: {{ include "report-app.fullname" . }}-config
- secretRef:
    name: {{ include "report-app.fullname" . }}-secret
{{- end -}}

{{/*
Hostname of the internal Postgres / Redis services. Indirected through a
helper so external-DB deployments (postgres.enabled=false) only need to
override the configmap.
*/}}
{{- define "report-app.dbHost" -}}
{{- if .Values.postgres.enabled -}}
{{ include "report-app.fullname" . }}-postgres
{{- else -}}
{{ .Values.postgres.externalHost | required "postgres.externalHost is required when postgres.enabled=false" }}
{{- end -}}
{{- end -}}

{{- define "report-app.redisHost" -}}
{{- if .Values.redis.enabled -}}
{{ include "report-app.fullname" . }}-redis
{{- else -}}
{{ .Values.redis.externalHost | required "redis.externalHost is required when redis.enabled=false" }}
{{- end -}}
{{- end -}}
