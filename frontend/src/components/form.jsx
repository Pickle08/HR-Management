import { useId } from 'react'

const base = 'mt-1 w-full rounded border bg-white px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400'

function Shell({ id, label, required, error, hint, children }) {
  return (
    <div>
      <div className="flex gap-1 text-sm font-medium text-slate-700">
        <label htmlFor={id}>{label}</label>
        {required && (
          <span className="text-red-600" aria-hidden="true">
            *
          </span>
        )}
      </div>
      {children}
      {error ? (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      ) : hint ? (
        <p id={`${id}-hint`} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

function controlProps(id, error, hint) {
  return {
    id,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': error ? `${id}-error` : hint ? `${id}-hint` : undefined,
    className: `${base} ${error ? 'border-red-400' : 'border-slate-300'}`,
  }
}

export function TextField({ label, error, hint, required, ...props }) {
  const id = useId()

  return (
    <Shell id={id} label={label} required={required} error={error} hint={hint}>
      <input {...controlProps(id, error, hint)} {...props} />
    </Shell>
  )
}

export function SelectField({ label, error, hint, required, children, ...props }) {
  const id = useId()

  return (
    <Shell id={id} label={label} required={required} error={error} hint={hint}>
      <select {...controlProps(id, error, hint)} {...props}>
        {children}
      </select>
    </Shell>
  )
}

export function TextAreaField({ label, error, hint, required, ...props }) {
  const id = useId()

  return (
    <Shell id={id} label={label} required={required} error={error} hint={hint}>
      <textarea rows={3} {...controlProps(id, error, hint)} {...props} />
    </Shell>
  )
}
