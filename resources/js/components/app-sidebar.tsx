import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import {
    Banknote,
    BookOpen,
    Bot,
    CalendarDays,
    FileText,
    Inbox,
    Landmark,
    LayoutGrid,
    Percent,
    Receipt,
    Scale,
    ShoppingCart,
    Tags,
    UserRound,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Inicio', url: '/dashboard', icon: LayoutGrid },
    { title: 'Socios', url: '/socios', icon: Users },
    { title: 'Grupos familiares', url: '/grupos-familiares', icon: UserRound },
    { title: 'Cuotas', url: '/cuotas', icon: CalendarDays },
    { title: 'Pagos', url: '/pagos', icon: Banknote },
    { title: 'Gastos', url: '/gastos', icon: ShoppingCart },
    { title: 'Libro de caja', url: '/libro-caja', icon: BookOpen },
    { title: 'Bandeja de Telegram', url: '/telegram/pendientes', icon: Inbox },
    { title: 'Impuestos del día', url: '/libro-caja/liquidaciones', icon: Receipt },
    { title: 'Conciliación', url: '/conciliacion', icon: Scale },
];

const configNavItems: NavItem[] = [
    { title: 'Planes y tarifas', url: '/planes', icon: Tags },
    { title: 'Cuentas y medios de pago', url: '/configuracion/cuentas', icon: Landmark },
    { title: 'Tributos', url: '/configuracion/tributos', icon: Percent },
    { title: 'Categorías de gasto', url: '/configuracion/categorias-gasto', icon: Tags },
    { title: 'Bot de Telegram', url: '/configuracion/telegram', icon: Bot },
    { title: 'Tipos de documento', url: '/configuracion/tipos-documento', icon: FileText },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavMain items={configNavItems} titulo="Configuración" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
