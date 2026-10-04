import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/** Activo si la URL coincide, pero un ítem "padre" (/libro-caja) no se marca cuando estamos en un hermano más específico. */
function esActivo(actual: string, url: string, items: NavItem[]): boolean {
    const ruta = actual.split('?')[0];
    if (ruta === url) return true;
    if (url === '/dashboard') return false;
    const hayMasEspecifico = items.some((i) => i.url !== url && i.url.startsWith(url + '/') && (ruta === i.url || ruta.startsWith(i.url + '/')));

    return ruta.startsWith(url + '/') && !hayMasEspecifico;
}

export function NavMain({ items = [], titulo = 'Gestión' }: { items: NavItem[]; titulo?: string }) {
    const page = usePage();
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{titulo}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={esActivo(page.url, item.url, items)}>
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
