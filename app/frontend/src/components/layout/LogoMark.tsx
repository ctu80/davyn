import { useTheme } from '@/lib/theme'

/**
 * The Davyn logo mark, swapped by theme (light mark in light mode, dark mark in
 * dark mode). The PNGs live in app/public/assets and are served by Caddy's
 * static /assets route. Size/extra styling come from `className`.
 */
export function LogoMark({ className = '', alt = 'Davyn' }: { className?: string; alt?: string }) {
  const { resolved } = useTheme()
  const logo = resolved === 'dark' ? '/assets/davyn-dark.png' : '/assets/davyn-light.png'
  return <img src={logo} alt={alt} className={className} />
}
